package com.remoterecorder.agent.recording

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import androidx.core.content.ContextCompat
import com.remoterecorder.agent.model.Command
import com.remoterecorder.agent.model.CommandType
import com.remoterecorder.agent.model.DeviceSchedule
import com.remoterecorder.agent.model.ErrorCode
import com.remoterecorder.agent.model.RecordingConfig
import com.remoterecorder.agent.network.ApiClient
import com.remoterecorder.agent.util.AgentLog
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

    @Volatile var state: State = State.IDLE
        private set

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
        val chunkTargetSeconds = 20

        val newRecorder = ChunkingAudioRecorder(
            context = context,
            config = resolved,
            chunkTargetSeconds = chunkTargetSeconds,
            onChunkReady = { chunk -> onChunkReady(localRecordingId, chunk) },
            onError = { onScheduledRecordingError(localRecordingId, it) },
        )

        activeRecordingId = localRecordingId
        activeCommandId = null // no server command backs this
        activeSource = Source.SCHEDULE_DEVICE
        state = State.RECORDING
        recorder = newRecorder
        newRecorder.start()

        PendingRecordingStore(context).markStarted(localRecordingId, schedule, startedAt, resolved)
        tryRegisterNow(localRecordingId, schedule, startedAt, resolved)

        return localRecordingId
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

    private fun onScheduledRecordingError(recordingId: String, error: Throwable) {
        AgentLog.e("session", "Scheduled recording error for $recordingId", error)
        state = State.IDLE
        recorder = null
        activeRecordingId = null
        activeSource = Source.NONE
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

    private fun onRecordingError(command: Command, error: Throwable) {
        AgentLog.e("session", "Recording error for ${command.recordingId}", error)
        acknowledge(command.commandId, "recording_error", errorCode = ErrorCode.SERVICE_ERROR.name, errorMessage = error.message)
        reportError(ErrorCode.SERVICE_ERROR, error.message, command.recordingId)

        state = State.IDLE
        recorder = null
        activeRecordingId = null
        activeCommandId = null
        activeSource = Source.NONE
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
