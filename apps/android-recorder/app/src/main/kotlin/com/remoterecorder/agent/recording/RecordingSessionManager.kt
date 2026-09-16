package com.remoterecorder.agent.recording

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import androidx.core.content.ContextCompat
import com.remoterecorder.agent.model.AudioEncoder
import com.remoterecorder.agent.model.Command
import com.remoterecorder.agent.model.CommandType
import com.remoterecorder.agent.model.DeviceSchedule
import com.remoterecorder.agent.model.ErrorCode
import com.remoterecorder.agent.model.RecordingConfig
import com.remoterecorder.agent.network.ApiClient
import com.remoterecorder.agent.util.AgentLog
import com.remoterecorder.agent.util.PendingRecording
import com.remoterecorder.agent.util.PendingRecordingStore
import com.remoterecorder.agent.work.ChunkUploadWorker
import org.json.JSONObject
import java.time.Instant
import java.util.UUID

/**
 * Owns the state machine for "is there an active recording, and what is
 * its id/config right now." Idempotency for START/STOP is enforced here:
 * a duplicate START while already recording is a no-op that still acks
 * (so the server doesn't wait forever for an ack that will never come),
 * and likewise for STOP.
 */
class RecordingSessionManager(
    private val context: Context,
    private val apiClient: ApiClient,
) {
    enum class State { IDLE, RECORDING, STOPPING }
    enum class Source { NONE, ADMIN, SCHEDULE_DEVICE }

    private var recorder: ChunkingAudioRecorder? = null
    private var activeRecordingId: String? = null
    private var activeCommandId: String? = null
    private var activeSource: Source = Source.NONE
    private var activeRetryCount: Int = 0

    @Volatile var state: State = State.IDLE
        private set

    private companion object {
        const val DEFAULT_CHUNK_TARGET_SECONDS = 20

        /** One automatic retry for a failed start/session before reporting FAILED to the admin. */
        const val MAX_START_RETRIES = 1
    }

    fun handleCommand(command: Command) {
        when (command.type) {
            CommandType.START_RECORDING -> handleStart(command)
            CommandType.STOP_RECORDING -> handleStop(command)
        }
    }

    /**
     * Locally-triggered start (from a fired schedule alarm), independent of
     * any server-pushed command. Skips command-ack semantics entirely —
     * there is no DeviceCommand row to ack against. Registers with the
     * server immediately if online; if that call fails, recording proceeds
     * anyway and registration is retried later by PendingRecordingSyncWorker
     * — audio capture is never gated on network reachability, matching the
     * existing STOP/chunk-upload philosophy.
     *
     * Returns the locally-generated recording id so the caller
     * (ScheduleAlarmReceiver) can arm the matching stop alarm.
     *
     * `@Synchronized` alongside handleStart/handleStop/stopFromSchedule:
     * cheap insurance against the admin-WebSocket-start and schedule-alarm
     * paths racing to mutate `state`/`recorder` at the same instant.
     */
    @Synchronized
    fun startFromSchedule(schedule: DeviceSchedule): String? {
        if (state != State.IDLE) {
            AgentLog.w("session", "Schedule fired while already $state; skipping (WebSocket admin-start or another schedule already active).")
            return null
        }
        if (!hasMicPermission()) {
            AgentLog.e("session", "Cannot start scheduled recording: RECORD_AUDIO not granted.")
            return null
        }

        val localRecordingId = UUID.randomUUID().toString()
        val startedAt = Instant.now()
        val resolved = CapabilityFallback.resolve(RecordingConfig.fromPreset(schedule.preset))

        activeRetryCount = 0
        beginScheduledRecording(localRecordingId, schedule, startedAt, resolved, startingChunkNumber = 0)

        return localRecordingId
    }

    /**
     * Builds and starts the recorder for a fresh schedule-triggered
     * session. Split out from [startFromSchedule] so
     * [onScheduledRecordingError] can call it again for a
     * same-recording-id retry without re-registering a new id with the
     * server or re-running the idle/permission checks. A post-process-kill
     * resume goes through [beginCapture] instead — see [resumeFromPending].
     */
    private fun beginScheduledRecording(localRecordingId: String, schedule: DeviceSchedule, startedAt: Instant, resolved: RecordingConfig, startingChunkNumber: Int) {
        val newRecorder = ChunkingAudioRecorder(
            context = context,
            config = resolved,
            chunkTargetSeconds = DEFAULT_CHUNK_TARGET_SECONDS,
            onChunkReady = { chunk -> onChunkReady(localRecordingId, chunk) },
            onError = { onScheduledRecordingError(localRecordingId, schedule, startedAt, resolved, it) },
        )

        activeRecordingId = localRecordingId
        activeCommandId = null // no server command backs this
        activeSource = Source.SCHEDULE_DEVICE
        state = State.RECORDING
        recorder = newRecorder
        newRecorder.start(startingChunkNumber)

        PendingRecordingStore(context).markStarted(localRecordingId, schedule, startedAt, resolved)
        tryRegisterNow(localRecordingId, schedule, startedAt, resolved)
    }

    /**
     * Resumes capture for a recording that was still active (never reached
     * `stopFromSchedule`) when the process was last killed — called from
     * `RecordingForegroundService.onCreate` after a START_STICKY restart.
     * Continues chunk numbering from `pending.lastChunkNumber` so the next
     * chunk doesn't collide with ones already uploaded for this recording;
     * the gap between the kill and this resume (at most a few chunks' worth
     * of audio) is an accepted, unavoidable loss — MediaRecorder itself
     * cannot survive a process death, only the already-flushed chunks and
     * this bookkeeping do.
     *
     * Returns false if capture could not be resumed (e.g. mic permission
     * revoked meanwhile) — the caller is expected to mark the recording
     * finished/stopped in that case so it isn't left dangling forever.
     */
    @Synchronized
    fun resumeFromPending(pending: PendingRecording): Boolean {
        if (state != State.IDLE) {
            AgentLog.w("session", "Resume requested for ${pending.localId} while already $state; ignoring.")
            return false
        }
        if (!hasMicPermission()) {
            AgentLog.e("session", "Cannot resume recording ${pending.localId}: RECORD_AUDIO not granted.")
            return false
        }

        val config = RecordingConfig(
            encoder = AudioEncoder.valueOf(pending.encoder.uppercase()),
            sampleRate = pending.sampleRate,
            bitrate = pending.bitrate,
            channels = pending.channels,
        )

        AgentLog.i("session", "Resuming recording ${pending.localId} from chunk #${pending.lastChunkNumber + 1} after process restart.")
        activeRetryCount = 0
        beginCapture(pending.localId, config, startingChunkNumber = pending.lastChunkNumber)
        return true
    }

    /**
     * Builds and starts the recorder for a resumed-after-kill session.
     * Unlike [beginScheduledRecording], this never re-registers or
     * re-marks-started with [PendingRecordingStore] — `pending` already
     * exists from the original startFromSchedule call, and re-touching it
     * here would reset bookkeeping (like lastChunkNumber) that the resume
     * itself depends on reading.
     */
    private fun beginCapture(recordingId: String, config: RecordingConfig, startingChunkNumber: Int) {
        val newRecorder = ChunkingAudioRecorder(
            context = context,
            config = config,
            chunkTargetSeconds = DEFAULT_CHUNK_TARGET_SECONDS,
            onChunkReady = { chunk -> onChunkReady(recordingId, chunk) },
            onError = { onResumedRecordingError(recordingId, config, startingChunkNumber, it) },
        )

        activeRecordingId = recordingId
        activeCommandId = null // no server command backs this
        activeSource = Source.SCHEDULE_DEVICE
        state = State.RECORDING
        recorder = newRecorder
        newRecorder.start(startingChunkNumber)
    }

    @Synchronized
    private fun onResumedRecordingError(recordingId: String, config: RecordingConfig, startingChunkNumber: Int, error: Throwable) {
        AgentLog.e("session", "Resumed recording error for $recordingId", error)
        if (activeRecordingId != recordingId) return // already superseded by a newer session

        recorder = null

        if (activeRetryCount < MAX_START_RETRIES) {
            activeRetryCount++
            AgentLog.w("session", "Retrying resumed recording start for $recordingId (attempt $activeRetryCount).")
            beginCapture(recordingId, config, startingChunkNumber)
            return
        }

        state = State.IDLE
        activeRecordingId = null
        activeSource = Source.NONE
        activeRetryCount = 0
    }

    /**
     * Locally-triggered stop, matching [startFromSchedule]. Reuses the
     * existing `/complete` finalize call directly (it's agnostic to
     * origin) rather than routing through `acknowledge()`, since there is
     * no command to ack.
     */
    @Synchronized
    fun stopFromSchedule(recordingId: String) {
        if (state == State.IDLE || activeRecordingId != recordingId) {
            AgentLog.w("session", "Scheduled stop for $recordingId does not match active state; ignoring.")
            return
        }

        state = State.STOPPING
        val finalChunk = recorder?.stop()
        recorder = null
        if (finalChunk != null) uploadChunkNow(recordingId, finalChunk)

        val store = PendingRecordingStore(context)
        store.markFinishedRecording(recordingId)
        runCatching { apiClient.completeRecording(recordingId) }
            .onSuccess { store.markCompleted(recordingId) }
            .onFailure { AgentLog.w("session", "Complete request failed for $recordingId; PendingRecordingSyncWorker will retry.", it) }

        state = State.IDLE
        activeRecordingId = null
        activeSource = Source.NONE
    }

    private fun tryRegisterNow(localRecordingId: String, schedule: DeviceSchedule, startedAt: Instant, config: RecordingConfig) {
        runCatching {
            apiClient.createRecording(
                clientRecordingId = localRecordingId,
                preset = schedule.preset,
                startedAt = startedAt.toString(),
                encoder = config.encoder.name.lowercase(),
                sampleRate = config.sampleRate,
                bitrate = config.bitrate,
                channels = config.channels,
                scheduleId = schedule.id,
            )
        }.onSuccess {
            PendingRecordingStore(context).markRegistered(localRecordingId)
        }.onFailure {
            AgentLog.w("session", "Immediate registration failed for $localRecordingId; PendingRecordingSyncWorker will retry.", it)
        }
    }

    @Synchronized
    private fun onScheduledRecordingError(recordingId: String, schedule: DeviceSchedule, startedAt: Instant, resolved: RecordingConfig, error: Throwable) {
        AgentLog.e("session", "Scheduled recording error for $recordingId", error)
        // The recorder's onError callback runs on its own capture/encode
        // thread, not the thread that called startFromSchedule — without
        // @Synchronized this races handleStart/handleStop/stopFromSchedule
        // mutating the same state/recorder/activeRecordingId fields from
        // whatever thread the WebSocket or alarm receiver runs on.
        if (activeRecordingId != recordingId) return // already superseded by a newer session

        recorder = null

        if (activeRetryCount < MAX_START_RETRIES) {
            activeRetryCount++
            AgentLog.w("session", "Retrying scheduled recording start for $recordingId (attempt $activeRetryCount).")
            beginScheduledRecording(recordingId, schedule, startedAt, resolved)
            return
        }

        state = State.IDLE
        activeRecordingId = null
        activeSource = Source.NONE
        activeRetryCount = 0
    }

    @Synchronized
    private fun handleStart(command: Command) {
        acknowledge(command.commandId, "command_received")

        if (state != State.IDLE) {
            AgentLog.w("session", "START_RECORDING received while already ${state}; treating as duplicate.")
            if (activeRecordingId == command.recordingId) {
                // Same recording the server already thinks is running — resend
                // the started ack so a lost ack doesn't wedge the server side.
                acknowledgeStarted(command.commandId)
            }
            return
        }

        if (!hasMicPermission()) {
            AgentLog.e("session", "Cannot start recording: RECORD_AUDIO not granted.")
            acknowledge(command.commandId, "recording_error", errorCode = ErrorCode.MIC_PERMISSION_DENIED.name, errorMessage = "Microphone permission not granted.")
            reportError(ErrorCode.MIC_PERMISSION_DENIED, "Microphone permission not granted.", command.recordingId)
            return
        }

        activeRetryCount = 0
        beginAdminRecording(command)
    }

    /**
     * Builds and starts the recorder for an admin-triggered session. Split
     * out from [handleStart] so [onRecordingError] can call it again for a
     * same-command retry without re-running the idle/permission checks
     * (which don't need re-checking — state is already RECORDING/retrying,
     * and permission can't have been revoked between the two attempts).
     */
    private fun beginAdminRecording(command: Command) {
        val requested = RecordingConfig.fromCommandPayload(command.payload)
        val resolved = CapabilityFallback.resolve(requested)
        val chunkTargetSeconds = command.payload.optInt("chunk_target_seconds", 20).coerceIn(5, 60)

        val newRecorder = ChunkingAudioRecorder(
            context = context,
            config = resolved,
            chunkTargetSeconds = chunkTargetSeconds,
            onChunkReady = { chunk -> onChunkReady(command.recordingId, chunk) },
            onError = { onRecordingError(command, it) },
        )

        activeRecordingId = command.recordingId
        activeCommandId = command.commandId
        activeSource = Source.ADMIN
        state = State.RECORDING
        recorder = newRecorder
        newRecorder.start()

        acknowledgeStarted(command.commandId, resolved)
    }

    @Synchronized
    private fun handleStop(command: Command) {
        acknowledge(command.commandId, "command_received")

        if (state == State.IDLE) {
            AgentLog.w("session", "STOP_RECORDING received while idle; treating as duplicate/no-op.")
            return
        }

        if (activeRecordingId != null && activeRecordingId != command.recordingId) {
            AgentLog.w("session", "STOP_RECORDING for ${command.recordingId} does not match active recording $activeRecordingId; ignoring.")
            return
        }

        state = State.STOPPING

        // The final chunk (which may be the *only* chunk, if the recording
        // was shorter than one chunk_target_seconds interval) is uploaded
        // synchronously here rather than just handed to WorkManager: /complete
        // is about to be called immediately after, and finalization fails if
        // no chunk has actually landed on the server yet. WorkManager still
        // owns retry for this same upload if it fails here (see
        // ChunkUploadWorker.enqueue's dedupe key), so a flaky network at stop
        // time doesn't lose the chunk — it just means /complete won't succeed
        // until that retry lands, which the dashboard can then re-trigger.
        val finalChunk = recorder?.stop()
        recorder = null

        if (finalChunk != null) {
            uploadChunkNow(activeRecordingId ?: command.recordingId, finalChunk)
        }

        val recordingId = activeRecordingId
        acknowledge(command.commandId, "recording_stopped")

        if (recordingId != null) {
            runCatching { apiClient.completeRecording(recordingId) }
                .onFailure { AgentLog.e("session", "Failed to request finalization for $recordingId; server can retry via dashboard.", it) }
        }

        state = State.IDLE
        activeRecordingId = null
        activeCommandId = null
        activeSource = Source.NONE
    }

    private fun onChunkReady(recordingId: String, chunk: ChunkingAudioRecorder.ChunkFile) {
        AgentLog.i("session", "Chunk #${chunk.chunkNumber} ready (${chunk.file.length()} bytes), enqueuing upload.")
        // No-op for admin-triggered recordings (no PendingRecordingStore
        // entry exists for those) — only relevant for schedule-triggered
        // ones, where it's what lets a post-kill resume continue numbering
        // correctly (see RecordingSessionManager.resumeFromPending).
        PendingRecordingStore(context).markChunkProduced(recordingId, chunk.chunkNumber)
        ChunkUploadWorker.enqueue(
            context = context,
            recordingId = recordingId,
            chunkNumber = chunk.chunkNumber,
            file = chunk.file,
            checksum = chunk.checksum,
            durationSeconds = chunk.durationSeconds,
            mimeType = chunk.mimeType,
        )
    }

    private fun uploadChunkNow(recordingId: String, chunk: ChunkingAudioRecorder.ChunkFile) {
        runCatching {
            apiClient.uploadChunk(recordingId, chunk.chunkNumber, chunk.checksum, chunk.durationSeconds, chunk.file, chunk.mimeType)
            AgentLog.i("session", "Final chunk #${chunk.chunkNumber} uploaded synchronously before completion request.")
            ChunkFileStore.delete(chunk.file)
        }.onFailure { error ->
            AgentLog.w("session", "Synchronous upload of final chunk #${chunk.chunkNumber} failed; falling back to WorkManager retry.", error)
            // Still hand it to WorkManager so the chunk isn't lost — /complete
            // will fail this time, but the dashboard can retry it once this
            // background upload eventually succeeds.
            ChunkUploadWorker.enqueue(
                context = context,
                recordingId = recordingId,
                chunkNumber = chunk.chunkNumber,
                file = chunk.file,
                checksum = chunk.checksum,
                durationSeconds = chunk.durationSeconds,
                mimeType = chunk.mimeType,
            )
        }
    }

    @Synchronized
    private fun onRecordingError(command: Command, error: Throwable) {
        AgentLog.e("session", "Recording error for ${command.recordingId}", error)
        // Runs on the recorder's own capture/encode thread — @Synchronized
        // for the same reason as onScheduledRecordingError.
        if (activeRecordingId != command.recordingId) return // already superseded

        recorder = null

        if (activeRetryCount < MAX_START_RETRIES) {
            activeRetryCount++
            AgentLog.w("session", "Retrying recording start for ${command.recordingId} (attempt $activeRetryCount).")
            beginAdminRecording(command)
            return
        }

        acknowledge(command.commandId, "recording_error", errorCode = ErrorCode.SERVICE_ERROR.name, errorMessage = error.message)
        reportError(ErrorCode.SERVICE_ERROR, error.message, command.recordingId)

        state = State.IDLE
        activeRecordingId = null
        activeCommandId = null
        activeSource = Source.NONE
        activeRetryCount = 0
    }

    private fun acknowledgeStarted(commandId: String, config: RecordingConfig? = null) {
        acknowledge(commandId, "recording_started", configuration = config?.toJson())
    }

    private fun acknowledge(commandId: String, event: String, configuration: JSONObject? = null, errorCode: String? = null, errorMessage: String? = null) {
        runCatching {
            apiClient.acknowledgeCommand(commandId, event, configuration, errorCode, errorMessage)
        }.onFailure {
            AgentLog.e("session", "Failed to acknowledge command $commandId ($event)", it)
        }
    }

    private fun reportError(code: ErrorCode, message: String?, recordingId: String?) {
        runCatching { apiClient.reportError(code.name, message, recordingId) }
    }

    private fun hasMicPermission(): Boolean {
        return ContextCompat.checkSelfPermission(context, Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED
    }

    fun isRecording(): Boolean = state == State.RECORDING
}
