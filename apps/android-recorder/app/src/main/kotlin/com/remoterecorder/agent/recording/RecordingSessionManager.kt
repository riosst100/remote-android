package com.remoterecorder.agent.recording

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import androidx.core.content.ContextCompat
import com.remoterecorder.agent.model.Command
import com.remoterecorder.agent.model.CommandType
import com.remoterecorder.agent.model.ErrorCode
import com.remoterecorder.agent.model.RecordingConfig
import com.remoterecorder.agent.network.ApiClient
import com.remoterecorder.agent.util.AgentLog
import com.remoterecorder.agent.work.ChunkUploadWorker
import org.json.JSONObject

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

    private var recorder: ChunkingAudioRecorder? = null
    private var activeRecordingId: String? = null
    private var activeCommandId: String? = null

    @Volatile var state: State = State.IDLE
        private set

    fun handleCommand(command: Command) {
        when (command.type) {
            CommandType.START_RECORDING -> handleStart(command)
            CommandType.STOP_RECORDING -> handleStop(command)
        }
    }

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
        state = State.RECORDING
        recorder = newRecorder
        newRecorder.start()

        acknowledgeStarted(command.commandId, resolved)
    }

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
        recorder?.stop()
        recorder = null

        val recordingId = activeRecordingId
        acknowledge(command.commandId, "recording_stopped")

        if (recordingId != null) {
            runCatching { apiClient.completeRecording(recordingId) }
                .onFailure { AgentLog.e("session", "Failed to request finalization for $recordingId; server can retry via dashboard.", it) }
        }

        state = State.IDLE
        activeRecordingId = null
        activeCommandId = null
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

    private fun onRecordingError(command: Command, error: Throwable) {
        AgentLog.e("session", "Recording error for ${command.recordingId}", error)
        acknowledge(command.commandId, "recording_error", errorCode = ErrorCode.SERVICE_ERROR.name, errorMessage = error.message)
        reportError(ErrorCode.SERVICE_ERROR, error.message, command.recordingId)

        state = State.IDLE
        recorder = null
        activeRecordingId = null
        activeCommandId = null
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
