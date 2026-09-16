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
import com.remoterecorder.agent.util.PendingRecording
import com.remoterecorder.agent.util.PendingRecordingStore
import com.remoterecorder.agent.util.RecordingCompletionStore
import com.remoterecorder.agent.work.ChunkUploadWorker
import com.remoterecorder.agent.work.RecordingCompletionSyncWorker
import org.json.JSONObject
import java.time.Instant
import java.util.UUID

/**
 * Owns the state machine for "is there an active recording, and what is
 * its id/config right now." Idempotency for START/STOP is enforced here:
 * a duplicate START while already recording is a no-op that still acks
 * (so the server doesn't wait forever for an ack that will never come),
 * and likewise for STOP.
 *
 * Recording is captured as one continuous, gapless file for the whole
 * session (see [ChunkingAudioRecorder]) and only split into upload-sized
 * parts — via [AdtsChunkSplitter] — once [stop] actually happens. This
 * trades a safety property the old rotate-every-20s approach had (a
 * crash mid-recording only lost the last few seconds, since prior chunks
 * were already uploaded) for gaplessness: a device crash or kill now
 * loses the entire in-progress recording, since nothing is split or
 * uploaded until the session ends. Accepted trade-off — see
 * [reportUnrecoverableAfterRestart], which now only reports the loss
 * rather than actually resuming capture, since there is no longer a
 * partial file to pick back up from.
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
        /** One automatic retry for a failed start before reporting FAILED to the admin. */
        const val MAX_START_RETRIES = 1

        /** Split the whole-session recording into ~2-minute upload parts. */
        const val TARGET_PART_BYTES = 2L * 60 * 32_000 // ~2 min at a 256kbps-class bitrate, rounded generously
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
     * — audio capture is never gated on network reachability.
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
        beginScheduledRecording(localRecordingId, schedule, startedAt, resolved)

        return localRecordingId
    }

    /**
     * Builds and starts the recorder for a schedule-triggered session.
     * Split out from [startFromSchedule] so [onScheduledRecordingError] can
     * call it again for a same-recording-id retry without re-registering a
     * new id with the server or re-running the idle/permission checks.
     */
    private fun beginScheduledRecording(localRecordingId: String, schedule: DeviceSchedule, startedAt: Instant, resolved: RecordingConfig) {
        val newRecorder = ChunkingAudioRecorder(
            context = context,
            config = resolved,
            onError = { onScheduledRecordingError(localRecordingId, schedule, startedAt, resolved, it) },
        )

        activeRecordingId = localRecordingId
        activeCommandId = null // no server command backs this
        activeSource = Source.SCHEDULE_DEVICE
        state = State.RECORDING
        recorder = newRecorder
        newRecorder.start()

        PendingRecordingStore(context).markStarted(localRecordingId, schedule, startedAt, resolved)
        tryRegisterNow(localRecordingId, schedule, startedAt, resolved)
    }

    /**
     * Called from `RecordingForegroundService.onCreate` after a
     * START_STICKY restart finds a recording that was still active (never
     * reached `stopFromSchedule`) when the process was last killed.
     *
     * Capture can no longer be resumed the way the old chunk-per-20s
     * design allowed — MediaRecorder doesn't survive a process death, and
     * with the whole session now living in one file that's still only
     * partially written, there's nothing salvageable to pick back up from
     * (unlike the old design, where prior chunks were already safely
     * uploaded). This just marks the recording finished/failed server-side
     * so it isn't left dangling in RECORDING state forever.
     */
    fun reportUnrecoverableAfterRestart(pending: PendingRecording) {
        AgentLog.w("session", "Recording ${pending.localId} was still active when the process was killed; the in-progress file could not survive and is being marked failed.")
        val store = PendingRecordingStore(context)
        store.markFinishedRecording(pending.localId)
        runCatching { apiClient.reportError("SERVICE_ERROR", "Recording lost: the app process was killed before it could be stopped.", pending.localId) }
        store.markCompleted(pending.localId)
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
        val recorded = recorder?.stop()
        recorder = null
        val allPartsUploaded = if (recorded != null) splitAndUpload(recordingId, recorded) else false

        // markFinishedRecording alone is enough here, regardless of
        // allPartsUploaded — PendingRecordingSyncWorker already retries
        // /complete for any (finished && !completed) entry on its own
        // periodic schedule, which covers the "a part fell back to
        // ChunkUploadWorker" case the same way RecordingCompletionStore
        // does for the admin-triggered path (that path has no
        // PendingRecordingStore entry to lean on instead).
        val store = PendingRecordingStore(context)
        store.markFinishedRecording(recordingId)

        if (allPartsUploaded) {
            runCatching { apiClient.completeRecording(recordingId) }
                .onSuccess { store.markCompleted(recordingId) }
                .onFailure { AgentLog.w("session", "Complete request failed for $recordingId; PendingRecordingSyncWorker will retry.", it) }
        } else {
            AgentLog.w("session", "Deferring /complete for $recordingId until the remaining part(s) finish uploading in the background; PendingRecordingSyncWorker will retry.")
        }

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
        // The recorder's onError callback runs on its own capture thread,
        // not the thread that called startFromSchedule — without
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

        val newRecorder = ChunkingAudioRecorder(
            context = context,
            config = resolved,
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

        val recorded = recorder?.stop()
        recorder = null
        val recordingId = activeRecordingId ?: command.recordingId

        val allPartsUploaded = if (recorded != null) splitAndUpload(recordingId, recorded) else false

        acknowledge(command.commandId, "recording_stopped")
        completeOrDefer(recordingId, allPartsUploaded)

        state = State.IDLE
        activeRecordingId = null
        activeCommandId = null
        activeSource = Source.NONE
    }

    /**
     * Calls `/complete` now if every part actually finished uploading, or
     * defers it to [RecordingCompletionSyncWorker] otherwise — calling
     * /complete before every part has landed server-side fails
     * finalization outright (see [splitAndUpload]).
     */
    private fun completeOrDefer(recordingId: String, allPartsUploaded: Boolean) {
        if (!allPartsUploaded) {
            AgentLog.w("session", "Deferring /complete for $recordingId until the remaining part(s) finish uploading in the background.")
            RecordingCompletionStore(context).markPending(recordingId)
            return
        }

        runCatching { apiClient.completeRecording(recordingId) }
            .onFailure {
                AgentLog.e("session", "Failed to request finalization for $recordingId; will retry.", it)
                RecordingCompletionStore(context).markPending(recordingId)
            }
    }

    /**
     * Splits the whole-session recording into upload-sized parts and
     * uploads each one *synchronously, in order*, blocking the caller
     * (STOPPING already gates the state machine, so this is safe to take
     * a few seconds). This matters because /complete must never be called
     * before every part has actually landed server-side — finalization
     * requires a contiguous, complete chunk sequence and fails outright
     * ("no chunks were uploaded", or a sequence gap) otherwise. Enqueuing
     * parts to WorkManager and calling /complete right after used to race
     * that background upload, since WorkManager runs asynchronously.
     *
     * Returns true only if every part uploaded successfully — the caller
     * uses this to decide whether it's actually safe to call /complete
     * now, or whether to defer to [RecordingCompletionStore] +
     * [RecordingCompletionSyncWorker] instead. A part that fails here
     * still falls back to ChunkUploadWorker (WorkManager retry/backoff),
     * so a flaky connection at stop time never loses a part — it just
     * means /complete has to wait for that background retry to land.
     *
     * The whole-session file itself is deleted once every part has been
     * split out of it — only the parts persist on disk from here on.
     */
    private fun splitAndUpload(recordingId: String, recorded: ChunkingAudioRecorder.RecordedFile): Boolean {
        val parts = try {
            AdtsChunkSplitter.split(recorded.file, TARGET_PART_BYTES) { partNumber ->
                ChunkFileStore.newChunkFile(context, partNumber)
            }
        } catch (e: Exception) {
            AgentLog.e("session", "Failed to split recording $recordingId into upload parts", e)
            runCatching { apiClient.reportError("SERVICE_ERROR", "Failed to split the recording for upload: ${e.message}", recordingId) }
            return false
        } finally {
            runCatching { recorded.file.delete() }
        }

        if (parts.isEmpty()) {
            AgentLog.w("session", "Recording $recordingId produced no audio to split.")
            return false
        }

        AgentLog.i("session", "Recording $recordingId split into ${parts.size} part(s); uploading synchronously.")
        var allSucceeded = true
        for (part in parts) {
            PendingRecordingStore(context).markChunkProduced(recordingId, part.partNumber)

            val uploaded = runCatching {
                apiClient.uploadChunk(recordingId, part.partNumber, part.checksum, 0, part.file, "audio/aac")
            }
            if (uploaded.isSuccess) {
                ChunkFileStore.delete(part.file)
            } else {
                allSucceeded = false
                AgentLog.w("session", "Synchronous upload of part #${part.partNumber} failed; falling back to WorkManager retry.", uploaded.exceptionOrNull())
                // Still hand it to WorkManager so the part isn't lost — /complete
                // will be deferred to RecordingCompletionSyncWorker once this
                // background upload eventually succeeds.
                ChunkUploadWorker.enqueue(
                    context = context,
                    recordingId = recordingId,
                    chunkNumber = part.partNumber,
                    file = part.file,
                    checksum = part.checksum,
                    durationSeconds = 0,
                    mimeType = "audio/aac",
                )
            }
        }

        return allSucceeded
    }

    @Synchronized
    private fun onRecordingError(command: Command, error: Throwable) {
        AgentLog.e("session", "Recording error for ${command.recordingId}", error)
        // Runs on the recorder's own capture thread — @Synchronized for the
        // same reason as onScheduledRecordingError.
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
