package com.remoterecorder.agent.recording

import android.content.Context
import android.media.MediaRecorder
import com.remoterecorder.agent.model.RecordingConfig
import com.remoterecorder.agent.util.AgentLog
import java.io.File
import java.security.MessageDigest

/**
 * Records one continuous, uninterrupted AAC/ADTS file for the whole
 * session — a single [MediaRecorder] instance runs from [start] to [stop]
 * with no restart in between, so there's no chunk-boundary gap the way
 * the old rotate-every-20s approach had (every rotation stopped and
 * restarted MediaRecorder, briefly releasing the mic).
 *
 * The file is split into upload-sized parts only *after* recording ends
 * — see [AdtsChunkSplitter] — rather than during capture, which is what
 * lets this stay on the MediaRecorder API that's actually proven stable
 * in production. (A continuous AudioRecord/MediaCodec pipeline that
 * split live was tried and reverted — see git history — after proving
 * too OEM-sensitive: silent zero-output and stop()-time races were
 * observed on Samsung and Xiaomi hardware respectively.)
 *
 * The file is written to the app's cache directory — temporary,
 * technically required storage, never a permanent copy (see
 * [ChunkFileStore]) — and [RecordingSessionManager] is responsible for
 * deleting it once every split part has been uploaded.
 */
class ChunkingAudioRecorder(
    private val context: Context,
    private val config: RecordingConfig,
    private val onError: (Throwable) -> Unit,
) {
    data class RecordedFile(val file: File, val checksum: String)

    private var recorder: MediaRecorder? = null
    private var outputFile: File? = null
    @Volatile private var isRecording = false

    fun start() {
        if (isRecording) return

        val file = ChunkFileStore.recordingFile(context)
        val newRecorder = try {
            buildRecorder(file).apply { prepare(); start() }
        } catch (e: Exception) {
            AgentLog.e("recorder", "Failed to start MediaRecorder", e)
            onError(e)
            return
        }

        recorder = newRecorder
        outputFile = file
        isRecording = true
        AgentLog.i("recorder", "Started continuous recording -> ${file.name}")
    }

    /**
     * Stops recording and returns the whole session's recorded file (if
     * any audio was actually captured), for the caller to split and
     * upload — see RecordingSessionManager.handleStop.
     *
     * MediaRecorder.stop() throwing is a real, OEM-visible failure mode
     * (observed on Xiaomi/MIUI in particular) — but release() must run
     * regardless, since a MediaRecorder that's never released keeps
     * holding the microphone: the recording indicator stays on and the
     * mic is unusable until the process dies, even though the app itself
     * has moved on to IDLE. That's the try/finally below, not just a
     * try/catch.
     */
    fun stop(): RecordedFile? {
        val current = recorder ?: return null
        val file = outputFile
        recorder = null
        outputFile = null
        isRecording = false

        var stopFailed = false
        try {
            current.stop()
        } catch (e: Exception) {
            AgentLog.e("recorder", "MediaRecorder.stop() failed; releasing anyway.", e)
            stopFailed = true
        } finally {
            runCatching { current.release() }
        }

        if (stopFailed || file == null) {
            onError(IllegalStateException("MediaRecorder.stop() failed for the recording session."))
            return null
        }

        return try {
            // Some OEM encoders (observed on Xiaomi/MIUI) can still be
            // flushing the last write(s) to disk for a brief moment after
            // stop() returns; hashing immediately can checksum a file that
            // hasn't finished being written, which then mismatches whatever
            // gets read moments later when splitting/uploading happens.
            // Waiting for the file's size to stop changing is a cheap,
            // portable way to know the OS is done writing it.
            waitForFileToStabilize(file)

            if (file.length() <= 0) return null

            RecordedFile(file, sha256(file))
        } catch (e: Exception) {
            AgentLog.e("recorder", "Failed to finalize the recorded file", e)
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

    /**
     * Polls the file's size a few times a short distance apart and returns
     * once two consecutive reads agree, as a portable proxy for "the OS has
     * finished writing this file." There's no cross-OEM API that reports
     * this directly, so size-stability is the practical signal — bounded to
     * a handful of short checks so this can never hang indefinitely.
     */
    private fun waitForFileToStabilize(file: File) {
        var previousSize = -1L
        repeat(5) {
            val currentSize = file.length()
            if (currentSize == previousSize && currentSize > 0) return
            previousSize = currentSize
            Thread.sleep(40)
        }
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
