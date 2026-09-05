package com.remoterecorder.agent.recording

import android.content.Context
import android.media.MediaRecorder
import com.remoterecorder.agent.model.RecordingConfig
import com.remoterecorder.agent.util.AgentLog
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.io.File
import java.security.MessageDigest

/**
 * Records audio in successive, independently-playable AAC/ADTS files
 * instead of one continuous stream. Every [chunkTargetSeconds] the current
 * [MediaRecorder] is stopped and a new one started into a fresh file, which
 * is what makes chunk upload possible without ever holding the full
 * recording in memory or needing to slice a single large file after the
 * fact.
 *
 * Chunks are written to the app's cache directory — temporary, technically
 * required storage, never a permanent copy (see [ChunkFileStore]).
 */
class ChunkingAudioRecorder(
    private val context: Context,
    private val config: RecordingConfig,
    private val chunkTargetSeconds: Int,
    private val onChunkReady: (ChunkFile) -> Unit,
    private val onError: (Throwable) -> Unit,
) {
    data class ChunkFile(val file: File, val chunkNumber: Int, val durationSeconds: Int, val checksum: String, val mimeType: String)

    private var recorder: MediaRecorder? = null
    private var chunkNumber = 0
    private var rotationJob: Job? = null
    private val scope = CoroutineScope(Dispatchers.IO + Job())
    @Volatile private var isRecording = false

    fun start() {
        if (isRecording) return
        isRecording = true
        chunkNumber = 0
        startNextChunk()
    }

    /**
     * Stops recording and returns the final chunk (if any audio was
     * actually captured), so the caller can make sure it's uploaded before
     * requesting finalization — see RecordingSessionManager.handleStop.
     */
    fun stop(): ChunkFile? {
        if (!isRecording) return null
        isRecording = false
        rotationJob?.cancel()
        return finishCurrentChunk()
    }

    private fun startNextChunk() {
        chunkNumber += 1
        val file = ChunkFileStore.newChunkFile(context, chunkNumber)

        val newRecorder = buildRecorder(file)

        try {
            newRecorder.prepare()
            newRecorder.start()
        } catch (e: Exception) {
            AgentLog.e("recorder", "Failed to start MediaRecorder", e)
            onError(e)
            return
        }

        recorder = newRecorder
        AgentLog.i("recorder", "Started chunk #$chunkNumber -> ${file.name}")

        rotationJob = scope.launch {
            delay(chunkTargetSeconds * 1000L)
            if (isRecording) {
                rotateChunk()
            }
        }
    }

    private fun rotateChunk() {
        val finished = finishCurrentChunk()
        if (finished != null) onChunkReady(finished)
        if (isRecording) startNextChunk()
    }

    /** Stops the active recorder (if any) and emits its finished chunk. */
    private fun finishCurrentChunk(): ChunkFile? {
        val current = recorder ?: return null
        val currentChunkNumber = chunkNumber

        return try {
            current.stop()
            current.release()
            recorder = null

            val file = ChunkFileStore.fileFor(context, currentChunkNumber)
            val checksum = sha256(file)
            val duration = estimateDurationSeconds(file, chunkTargetSeconds)

            ChunkFile(file, currentChunkNumber, duration, checksum, "audio/aac")
        } catch (e: Exception) {
            AgentLog.e("recorder", "Failed to finalize chunk #$currentChunkNumber", e)
            onError(e)
            null
        }
    }

    private fun buildRecorder(outputFile: File): MediaRecorder {
        @Suppress("DEPRECATION")
        val newRecorder = if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.S) {
            MediaRecorder(context)
        } else {
            MediaRecorder()
        }

        return newRecorder.apply {
            setAudioSource(MediaRecorder.AudioSource.MIC)
            setOutputFormat(MediaRecorder.OutputFormat.AAC_ADTS)
            setAudioEncoder(MediaRecorder.AudioEncoder.AAC)
            setAudioSamplingRate(config.sampleRate)
            setAudioEncodingBitRate(config.bitrate)
            setAudioChannels(config.channels)
            setOutputFile(outputFile.absolutePath)
        }
    }

    private fun estimateDurationSeconds(file: File, target: Int): Int {
        // MediaRecorder doesn't give us a precise duration on stop for a raw
        // ADTS stream without a full parse; the target chunk length is an
        // accurate-enough value for chunk bookkeeping (the final recording's
        // true duration is derived server-side from start/stop timestamps).
        return if (file.length() > 0) target else 0
    }

    private fun sha256(file: File): String {
        val digest = MessageDigest.getInstance("SHA-256")
        file.inputStream().use { input ->
            val buffer = ByteArray(8192)
            var read: Int
            while (input.read(buffer).also { read = it } != -1) {
                digest.update(buffer, 0, read)
            }
        }
        return digest.digest().joinToString("") { "%02x".format(it) }
    }
}
