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
 * The next recorder is built and prepare()'d *before* the current one is
 * stopped (see [preWarmNextRecorder]), so the only gap in mic capture at
 * each rotation is the time [MediaRecorder.stop] and [MediaRecorder.start]
 * themselves take — prepare() (typically the slower step, since it opens
 * the encoder/output file) happens concurrently with the outgoing
 * recorder still capturing. This doesn't eliminate the gap the way a
 * continuous AudioRecord/MediaCodec pipeline would (that approach was
 * tried and reverted — see git history — after proving too OEM-sensitive:
 * silent zero-output and stop()-time races were observed on Samsung and
 * Xiaomi hardware respectively), but it meaningfully shortens it while
 * keeping the MediaRecorder path that's actually proven stable in
 * production.
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
    private var preWarmJob: Job? = null
    private val scope = CoroutineScope(Dispatchers.IO + Job())
    @Volatile private var isRecording = false

    /** The next chunk's already-prepare()'d recorder and file, built ahead of the rotation that will use them. */
    private data class PreWarmed(val recorder: MediaRecorder, val file: File, val chunkNumber: Int)
    private var preWarmed: PreWarmed? = null

    /**
     * @param startingChunkNumber Numbering continues from here + 1 rather
     * than always restarting at 1 — needed when resuming a recording after
     * the process was killed mid-session (see RecordingForegroundService's
     * resume-on-create path), so the next chunk doesn't collide with
     * chunk numbers already uploaded for this recording.
     */
    fun start(startingChunkNumber: Int = 0) {
        if (isRecording) return
        isRecording = true
        chunkNumber = startingChunkNumber
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
        preWarmJob?.cancel()
        discardPreWarmed()
        return finishCurrentChunk()
    }

    private fun startNextChunk() {
        chunkNumber += 1
        val file = ChunkFileStore.newChunkFile(context, chunkNumber)

        val newRecorder = try {
            buildRecorder(file).apply { prepare(); start() }
        } catch (e: Exception) {
            AgentLog.e("recorder", "Failed to start MediaRecorder", e)
            onError(e)
            return
        }

        recorder = newRecorder
        AgentLog.i("recorder", "Started chunk #$chunkNumber -> ${file.name}")

        schedulePreWarmAndRotation()
    }

    /**
     * Arms two timers off the same chunk window: one fires early enough
     * before the rotation deadline to build+prepare() the next recorder
     * ahead of time (the part of the old flow that used to happen only
     * after stop(), adding to the gap), and the actual rotation timer
     * fires at the normal chunk boundary and consumes whatever got
     * pre-warmed.
     */
    private fun schedulePreWarmAndRotation() {
        val chunkMillis = chunkTargetSeconds * 1000L
        val preWarmDelay = (chunkMillis - PRE_WARM_LEAD_MILLIS).coerceAtLeast(0L)

        preWarmJob = scope.launch {
            delay(preWarmDelay)
            if (isRecording) preWarmNextRecorder()
        }

        rotationJob = scope.launch {
            delay(chunkMillis)
            if (isRecording) rotateChunk()
        }
    }

    /**
     * Builds and prepare()'s the next chunk's MediaRecorder ahead of the
     * actual rotation, while the current recorder is still capturing.
     * prepare() is normally the slowest step (it opens the encoder and
     * output file) — doing it early means rotateChunk() only has to pay
     * for stop() + start(), not stop() + build + prepare() + start().
     */
    private fun preWarmNextRecorder() {
        if (preWarmed != null) return // already warmed for this cycle

        val nextChunkNumber = chunkNumber + 1
        val file = ChunkFileStore.newChunkFile(context, nextChunkNumber)

        try {
            val warmed = buildRecorder(file).apply { prepare() }
            preWarmed = PreWarmed(warmed, file, nextChunkNumber)
        } catch (e: Exception) {
            // Not fatal on its own — rotateChunk() falls back to building
            // the recorder the old (unwarmed) way if pre-warming failed.
            AgentLog.w("recorder", "Pre-warming next MediaRecorder failed; will build it at rotation time instead.", e)
            preWarmed = null
        }
    }

    private fun discardPreWarmed() {
        preWarmed?.let { warm ->
            runCatching { warm.recorder.release() }
            runCatching { warm.file.delete() }
        }
        preWarmed = null
    }

    private fun rotateChunk() {
        val finished = finishCurrentChunk()
        if (finished != null) onChunkReady(finished)
        if (isRecording) startNextChunkFromPreWarmOrFresh()
    }

    private fun startNextChunkFromPreWarmOrFresh() {
        val warm = preWarmed
        preWarmed = null

        if (warm == null) {
            startNextChunk()
            return
        }

        try {
            warm.recorder.start()
        } catch (e: Exception) {
            AgentLog.e("recorder", "Failed to start pre-warmed MediaRecorder", e)
            runCatching { warm.recorder.release() }
            runCatching { warm.file.delete() }
            onError(e)
            return
        }

        chunkNumber = warm.chunkNumber
        recorder = warm.recorder
        AgentLog.i("recorder", "Started chunk #$chunkNumber -> ${warm.file.name} (pre-warmed)")

        schedulePreWarmAndRotation()
    }

    /**
     * Stops the active recorder (if any) and emits its finished chunk.
     *
     * MediaRecorder.stop() throwing is a real, OEM-visible failure mode
     * (observed on Xiaomi/MIUI in particular, e.g. when a chunk is very
     * short) — but release() must run regardless, since a MediaRecorder
     * that's never released keeps holding the microphone: the recording
     * indicator stays on and the mic is unusable until the process dies,
     * even though the app itself has moved on to IDLE. That's the
     * try/finally below, not just a try/catch.
     */
    private fun finishCurrentChunk(): ChunkFile? {
        val current = recorder ?: return null
        val currentChunkNumber = chunkNumber
        recorder = null

        var stopFailed = false
        try {
            current.stop()
        } catch (e: Exception) {
            AgentLog.e("recorder", "MediaRecorder.stop() failed for chunk #$currentChunkNumber; releasing anyway.", e)
            stopFailed = true
        } finally {
            runCatching { current.release() }
        }

        if (stopFailed) {
            onError(IllegalStateException("MediaRecorder.stop() failed for chunk #$currentChunkNumber"))
            return null
        }

        return try {
            val file = ChunkFileStore.fileFor(context, currentChunkNumber)

            // Some OEM encoders (observed on Xiaomi/MIUI) can still be
            // flushing the last write(s) to disk for a brief moment after
            // stop() returns; hashing immediately can checksum a file that
            // hasn't finished being written, which then mismatches whatever
            // OkHttp actually reads moments later when the upload happens.
            // Waiting for the file's size to stop changing is a cheap,
            // portable way to know the OS is done writing it.
            waitForFileToStabilize(file)

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

    /**
     * Polls the file's size a few times a short distance apart and returns
     * once two consecutive reads agree, as a portable proxy for "the OS has
     * finished writing this file." There's no cross-OEM API that reports
     * this directly, so size-stability is the practical signal — bounded to
     * a handful of short checks so a chunk can never hang here indefinitely.
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

    companion object {
        /** How far ahead of the chunk-rotation deadline to pre-warm the next MediaRecorder. */
        private const val PRE_WARM_LEAD_MILLIS = 500L
    }
}
