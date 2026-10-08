package com.remoterecorder.agent.recording

import android.content.Context
import com.remoterecorder.agent.model.Command
import com.remoterecorder.agent.model.CommandType
import com.remoterecorder.agent.network.ApiClient
import com.remoterecorder.agent.util.AgentLog
import com.remoterecorder.agent.work.ChunkUploadWorker
import java.io.File
import java.security.MessageDigest

/**
 * Owns a single background video-recording session: START_VIDEO begins
 * capture (no preview, rear camera, into internal cache), STOP_VIDEO ends
 * it, then the finished MP4 is uploaded as one part and the server merges
 * (ffmpeg passthrough) and makes it downloadable.
 *
 * Mirrors [RecordingSessionManager] but much simpler: video is one file, one
 * upload part — no live chunking. Upload still goes through the same
 * background path (synchronous attempt, then [ChunkUploadWorker] retry) so a
 * failed upload is retried without the app needing to stay open.
 *
 * Idempotency for START/STOP is keyed on commandId, same contract as audio.
 */
class VideoSessionManager(
    private val context: Context,
    private val apiClient: ApiClient,
    private val onBeforeCameraOpen: () -> Unit = {},
) {
    enum class State { IDLE, RECORDING, STOPPING }

    @Volatile private var state: State = State.IDLE
    private var activeRecordingId: String? = null
    private var recorder: VideoRecorder? = null

    fun isRecording(): Boolean = state == State.RECORDING

    @Synchronized
    fun handleCommand(command: Command) {
        when (command.type) {
            CommandType.START_VIDEO -> handleStart(command)
            CommandType.STOP_VIDEO -> handleStop(command)
            else -> AgentLog.w("video", "Ignoring non-video command ${command.type}")
        }
    }

    private fun handleStart(command: Command) {
        acknowledge(command.commandId, "command_received")

        if (state != State.IDLE) {
            AgentLog.w("video", "START_VIDEO while $state; treating as duplicate.")
            if (activeRecordingId == command.recordingId) {
                acknowledge(command.commandId, "recording_started")
            }
            return
        }

        val newRecorder = VideoRecorder(context) { onRecordingError(command, it) }
        if (!newRecorder.hasCameraPermission()) {
            AgentLog.e("video", "Cannot start video: CAMERA not granted.")
            acknowledge(command.commandId, "recording_error", errorCode = "SERVICE_ERROR", errorMessage = "Camera permission not granted.")
            runCatching { apiClient.reportError("SERVICE_ERROR", "Camera permission not granted.", command.recordingId) }
            return
        }

        // Promote the foreground service to the camera type before opening the
        // camera, or the OS (Android 14+, and MIUI generally) blocks it.
        runCatching { onBeforeCameraOpen() }

        val file = newRecorder.start()
        if (file == null) {
            acknowledge(command.commandId, "recording_error", errorCode = "SERVICE_ERROR", errorMessage = "Video recorder failed to start.")
            return
        }

        activeRecordingId = command.recordingId
        recorder = newRecorder
        state = State.RECORDING
        ActuatorState.setVideoRecording(true)
        acknowledge(command.commandId, "recording_started")
        AgentLog.i("video", "Video recording started for ${command.recordingId}")
    }

    private fun handleStop(command: Command) {
        acknowledge(command.commandId, "command_received")

        if (state != State.RECORDING) {
            AgentLog.w("video", "STOP_VIDEO while $state; nothing to stop.")
            return
        }

        state = State.STOPPING
        val recordingId = activeRecordingId
        val activeRecorder = recorder

        val file = runCatching { activeRecorder?.stop() }.getOrNull()
        recorder = null
        state = State.IDLE
        ActuatorState.setVideoRecording(false)

        if (file == null || recordingId == null) {
            AgentLog.w("video", "Video stop produced no file.")
            runCatching { apiClient.reportError("SERVICE_ERROR", "Video capture produced no file.", recordingId) }
            activeRecordingId = null
            return
        }

        uploadAndComplete(recordingId, file)
        activeRecordingId = null
    }

    /**
     * Uploads the single MP4 as part 0, then tells the server to finalize.
     * A failed synchronous upload is handed to [ChunkUploadWorker] so it is
     * retried in the background; finalization then waits for that worker.
     */
    private fun uploadAndComplete(recordingId: String, file: File) {
        val checksum = sha256(file)

        val uploaded = runCatching {
            apiClient.uploadChunk(recordingId, 0, checksum, 0, file, "video/mp4")
        }

        if (uploaded.isSuccess) {
            runCatching { file.delete() }
            runCatching { apiClient.completeRecording(recordingId) }
                .onFailure { AgentLog.e("video", "completeRecording failed for $recordingId", it) }
            AgentLog.i("video", "Video $recordingId uploaded and completion requested.")
        } else {
            AgentLog.w("video", "Synchronous video upload failed; handing to WorkManager.", uploaded.exceptionOrNull())
            ChunkUploadWorker.enqueue(
                context = context,
                recordingId = recordingId,
                chunkNumber = 0,
                file = file,
                checksum = checksum,
                durationSeconds = 0,
                mimeType = "video/mp4",
            )
            // Completion is deferred: the worker's success path triggers the
            // same /complete flow audio uses once the part is uploaded.
        }
    }

    private fun onRecordingError(command: Command, error: Throwable) {
        AgentLog.e("video", "Video recording error for ${command.recordingId}", error)
        state = State.IDLE
        recorder = null
        activeRecordingId = null
        ActuatorState.setVideoRecording(false)
        acknowledge(command.commandId, "recording_error", errorCode = "SERVICE_ERROR", errorMessage = error.message)
        runCatching { apiClient.reportError("SERVICE_ERROR", error.message, command.recordingId) }
    }

    private fun acknowledge(commandId: String, event: String, errorCode: String? = null, errorMessage: String? = null) {
        runCatching {
            apiClient.acknowledgeCommand(commandId, event, errorCode = errorCode, errorMessage = errorMessage)
        }.onFailure { AgentLog.e("video", "Failed to ack $commandId ($event)", it) }
    }

    private fun sha256(file: File): String {
        val digest = MessageDigest.getInstance("SHA-256")
        file.inputStream().use { stream ->
            val buffer = ByteArray(8192)
            var read = stream.read(buffer)
            while (read != -1) {
                digest.update(buffer, 0, read)
                read = stream.read(buffer)
            }
        }
        return digest.digest().joinToString("") { "%02x".format(it) }
    }
}
