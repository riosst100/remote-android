package com.remoterecorder.agent.recording

import android.content.Context
import android.media.AudioFormat
import android.media.AudioRecord
import android.media.MediaCodec
import android.media.MediaCodecInfo
import android.media.MediaFormat
import com.remoterecorder.agent.model.AudioEncoder
import com.remoterecorder.agent.model.RecordingConfig
import com.remoterecorder.agent.util.AgentLog
import java.io.File
import java.io.FileOutputStream
import java.security.MessageDigest
import java.util.concurrent.LinkedBlockingQueue
import kotlin.concurrent.thread

/**
 * Records audio as one continuous, uninterrupted capture — [AudioRecord]
 * and the [MediaCodec] encoder are opened once per session and never
 * stopped/restarted between chunks — while still emitting successive,
 * independently-playable chunk files exactly like the old
 * MediaRecorder-per-chunk approach did.
 *
 * The previous implementation stopped and restarted MediaRecorder every
 * [chunkTargetSeconds] to produce each chunk file, which meant the
 * microphone briefly stopped capturing at every chunk boundary (observed
 * as an audible gap when chunks are played back-to-back). Here the chunk
 * boundary is purely a decision about where to close one output file and
 * open the next among encoded frames flowing continuously off the
 * encoder — capture itself never pauses.
 *
 * Encoded frames are self-contained (ADTS-framed AAC, or FLAC's own
 * native framing), so splitting the stream between frames produces chunk
 * files that remain independently valid and byte-concatenable, same as
 * before.
 */
class ChunkingAudioRecorder(
    private val context: Context,
    private val config: RecordingConfig,
    private val chunkTargetSeconds: Int,
    private val onChunkReady: (ChunkFile) -> Unit,
    private val onError: (Throwable) -> Unit,
) {
    data class ChunkFile(val file: File, val chunkNumber: Int, val durationSeconds: Int, val checksum: String, val mimeType: String)

    private var audioRecord: AudioRecord? = null
    private var codec: MediaCodec? = null
    private var captureThread: Thread? = null
    private var encoderThread: Thread? = null
    @Volatile private var isRecording = false
    @Volatile private var stopRequested = false

    private val pcmQueue = LinkedBlockingQueue<PcmBuffer>()
    private data class PcmBuffer(val data: ByteArray, val length: Int, val endOfStream: Boolean = false)

    private var chunkWriter: ChunkWriter? = null
    private var chunkNumber = 0
    private var chunkStartNanos = 0L
    private val chunkTargetNanos get() = chunkTargetSeconds * 1_000_000_000L

    private val lastChunkLock = Object()
    private var lastFinishedChunk: ChunkFile? = null

    /**
     * @param startingChunkNumber Numbering continues from here + 1 rather
     * than always restarting at 1 — needed when resuming a recording after
     * the process was killed mid-session (see RecordingForegroundService's
     * resume-on-create path), so the next chunk doesn't collide with
     * chunk numbers already uploaded for this recording.
     */
    fun start(startingChunkNumber: Int = 0) {
        if (isRecording) return

        val mimeType = mimeTypeFor(config.encoder)
        val channelConfig = if (config.channels >= 2) AudioFormat.CHANNEL_IN_STEREO else AudioFormat.CHANNEL_IN_MONO
        val minBuffer = AudioRecord.getMinBufferSize(config.sampleRate, channelConfig, AudioFormat.ENCODING_PCM_16BIT)
        if (minBuffer <= 0) {
            onError(IllegalStateException("AudioRecord.getMinBufferSize returned $minBuffer for this configuration."))
            return
        }
        val bufferSize = minBuffer * 4

        val record = try {
            AudioRecord(
                android.media.MediaRecorder.AudioSource.MIC,
                config.sampleRate,
                channelConfig,
                AudioFormat.ENCODING_PCM_16BIT,
                bufferSize,
            )
        } catch (e: Exception) {
            onError(e)
            return
        }

        if (record.state != AudioRecord.STATE_INITIALIZED) {
            record.release()
            onError(IllegalStateException("AudioRecord failed to initialize."))
            return
        }

        val mediaCodec = try {
            buildEncoder(mimeType)
        } catch (e: Exception) {
            record.release()
            onError(e)
            return
        }

        mediaCodec.start()
        record.startRecording()

        // startRecording() doesn't throw on failure — it can silently leave
        // the AudioRecord in RECORDSTATE_STOPPED (observed when another app
        // or the system HAL already holds the mic). Left unchecked, the
        // capture thread would then block forever on read() with zero
        // chunks ever produced, only surfacing as a failure much later when
        // stop() is eventually called — reported here immediately instead.
        if (record.recordingState != AudioRecord.RECORDSTATE_RECORDING) {
            runCatching { mediaCodec.stop() }
            mediaCodec.release()
            record.release()
            onError(IllegalStateException("AudioRecord.startRecording() did not enter RECORDSTATE_RECORDING (mic likely unavailable)."))
            return
        }

        audioRecord = record
        codec = mediaCodec
        chunkNumber = startingChunkNumber
        stopRequested = false
        isRecording = true

        chunkWriter = ChunkWriter(context, config.encoder, ++chunkNumber).also { it.open() }
        chunkStartNanos = System.nanoTime()

        captureThread = thread(name = "audio-capture") { captureLoop(record, bufferSize) }
        encoderThread = thread(name = "audio-encode") { encodeLoop(mediaCodec, mimeType) }
    }

    /**
     * Stops recording and returns the final chunk (if any audio was
     * actually captured), so the caller can make sure it's uploaded before
     * requesting finalization — see RecordingSessionManager.handleStop.
     */
    fun stop(): ChunkFile? {
        if (!isRecording) return null
        stopRequested = true

        // AudioRecord.read() blocks until data is available; setting
        // stopRequested alone doesn't wake a thread parked inside a
        // blocking read call. record.stop() does unblock it (read()
        // returns ERROR_INVALID_OPERATION once the record is stopped),
        // which is what lets captureLoop's finally block push the
        // end-of-stream sentinel that the encoder loop is waiting on.
        audioRecord?.let { runCatching { it.stop() } }

        captureThread?.join(5_000)
        encoderThread?.join(5_000)

        isRecording = false
        audioRecord?.let { runCatching { it.release() } }
        // Only stop/release the codec once the encoder thread has actually
        // finished with it — releasing out from under a still-running
        // encoderLoop iteration throws IllegalStateException on the
        // encoder thread, which used to get misreported as a recording
        // failure even though real audio had already been captured.
        if (encoderThread?.isAlive != true) {
            codec?.let { runCatching { it.stop() }; runCatching { it.release() } }
        }
        audioRecord = null
        codec = null

        synchronized(lastChunkLock) {
            val result = lastFinishedChunk
            lastFinishedChunk = null
            return result
        }
    }

    private fun captureLoop(record: AudioRecord, bufferSize: Int) {
        val buffer = ByteArray(bufferSize)
        try {
            while (!stopRequested) {
                val read = record.read(buffer, 0, buffer.size)
                if (read > 0) {
                    pcmQueue.put(PcmBuffer(buffer.copyOf(read), read))
                }
            }
        } catch (e: Exception) {
            AgentLog.e("recorder", "Audio capture loop failed", e)
            // A stop-in-progress makes record.read() throw as a normal,
            // expected consequence of record.stop() unblocking it — not a
            // real recording failure, so it shouldn't be reported as one.
            if (!stopRequested) onError(e)
        } finally {
            runCatching { record.stop() }
            pcmQueue.put(PcmBuffer(ByteArray(0), 0, endOfStream = true))
        }
    }

    private fun encodeLoop(mediaCodec: MediaCodec, mimeType: String) {
        val bufferInfo = MediaCodec.BufferInfo()
        var sawEndOfStream = false

        try {
            while (!sawEndOfStream) {
                val pending = pcmQueue.take()

                if (pending.length > 0) {
                    feedInput(mediaCodec, pending.data, pending.length)
                }
                if (pending.endOfStream) {
                    feedEndOfStream(mediaCodec)
                }

                var draining = true
                while (draining) {
                    val outIndex = mediaCodec.dequeueOutputBuffer(bufferInfo, 10_000)
                    when {
                        outIndex >= 0 -> {
                            if (bufferInfo.flags and MediaCodec.BUFFER_FLAG_END_OF_STREAM != 0) {
                                sawEndOfStream = true
                            }
                            if (bufferInfo.size > 0 && bufferInfo.flags and MediaCodec.BUFFER_FLAG_CODEC_CONFIG == 0) {
                                val encoded = mediaCodec.getOutputBuffer(outIndex)
                                if (encoded != null) {
                                    writeEncodedFrame(encoded, bufferInfo, mimeType)
                                }
                            }
                            mediaCodec.releaseOutputBuffer(outIndex, false)
                        }
                        outIndex == MediaCodec.INFO_TRY_AGAIN_LATER -> draining = false
                        else -> { /* format changed / output buffers changed: nothing to do */ }
                    }
                }
            }
        } catch (e: Exception) {
            AgentLog.e("recorder", "Audio encode loop failed", e)
            if (!stopRequested) onError(e)
        } finally {
            finishActiveChunk()
        }
    }

    private fun feedInput(mediaCodec: MediaCodec, data: ByteArray, length: Int) {
        var offset = 0
        while (offset < length) {
            val inIndex = mediaCodec.dequeueInputBuffer(10_000)
            if (inIndex < 0) continue
            val inputBuffer = mediaCodec.getInputBuffer(inIndex) ?: continue
            val chunkLen = minOf(inputBuffer.capacity(), length - offset)
            inputBuffer.clear()
            inputBuffer.put(data, offset, chunkLen)
            mediaCodec.queueInputBuffer(inIndex, 0, chunkLen, System.nanoTime() / 1000, 0)
            offset += chunkLen
        }
    }

    private fun feedEndOfStream(mediaCodec: MediaCodec) {
        val inIndex = mediaCodec.dequeueInputBuffer(10_000)
        if (inIndex >= 0) {
            mediaCodec.queueInputBuffer(inIndex, 0, 0, System.nanoTime() / 1000, MediaCodec.BUFFER_FLAG_END_OF_STREAM)
        }
    }

    /**
     * Writes one encoded frame to the current chunk file, rotating to a
     * new chunk file first if this frame would cross the chunk boundary.
     * Rotation only ever happens between frames — never mid-frame — so
     * every chunk file stays independently valid.
     */
    private fun writeEncodedFrame(buffer: java.nio.ByteBuffer, info: MediaCodec.BufferInfo, mimeType: String) {
        val now = System.nanoTime()
        if (now - chunkStartNanos >= chunkTargetNanos) {
            rotateChunk()
        }

        val bytes = ByteArray(info.size)
        buffer.position(info.offset)
        buffer.get(bytes, 0, info.size)

        val framed = if (mimeType == MediaFormat.MIMETYPE_AUDIO_AAC) wrapInAdts(bytes, config.sampleRate, config.channels) else bytes
        chunkWriter?.write(framed)
    }

    private fun rotateChunk() {
        finishActiveChunk()?.let { onChunkReady(it) }
        if (!stopRequested) {
            chunkWriter = ChunkWriter(context, config.encoder, ++chunkNumber).also { it.open() }
            chunkStartNanos = System.nanoTime()
        }
    }

    private fun finishActiveChunk(): ChunkFile? {
        val writer = chunkWriter ?: return null
        chunkWriter = null
        val finished = writer.closeAndDescribe(chunkTargetSeconds)
        synchronized(lastChunkLock) { lastFinishedChunk = finished }
        return finished
    }

    private fun buildEncoder(mimeType: String): MediaCodec {
        val format = MediaFormat.createAudioFormat(mimeType, config.sampleRate, config.channels)
        when (mimeType) {
            MediaFormat.MIMETYPE_AUDIO_AAC -> {
                format.setInteger(MediaFormat.KEY_BIT_RATE, config.bitrate)
                format.setInteger(MediaFormat.KEY_AAC_PROFILE, MediaCodecInfo.CodecProfileLevel.AACObjectLC)
            }
            MediaFormat.MIMETYPE_AUDIO_FLAC -> {
                format.setInteger(MediaFormat.KEY_FLAC_COMPRESSION_LEVEL, 5)
            }
            else -> {
                format.setInteger(MediaFormat.KEY_BIT_RATE, config.bitrate)
            }
        }

        // Some OEM MediaCodecList implementations (observed on Xiaomi/MIUI)
        // resolve createEncoderByType to an unrelated codec instead of
        // throwing when the requested mime type isn't really available in
        // hardware — configure() then "succeeds" but silently encodes with
        // whatever codec was actually selected. Checking the codec's own
        // declared supported types against what we asked for turns that
        // mismatch into a real, reported error instead of silently wrong
        // audio (e.g. a FLAC request quietly producing an AAC stream).
        val codec = MediaCodec.createEncoderByType(mimeType)
        val declaresRequestedType = codec.codecInfo.supportedTypes.any { it.equals(mimeType, ignoreCase = true) }
        if (!declaresRequestedType) {
            val codecName = codec.codecInfo.name
            codec.release()
            throw IllegalStateException("MediaCodec.createEncoderByType($mimeType) returned '$codecName', which doesn't declare support for $mimeType.")
        }

        codec.configure(format, null, null, MediaCodec.CONFIGURE_FLAG_ENCODE)
        return codec
    }

    private fun mimeTypeFor(encoder: AudioEncoder): String = when (encoder) {
        AudioEncoder.AAC -> MediaFormat.MIMETYPE_AUDIO_AAC
        AudioEncoder.FLAC -> MediaFormat.MIMETYPE_AUDIO_FLAC
        AudioEncoder.OPUS -> MediaFormat.MIMETYPE_AUDIO_OPUS
    }

    /**
     * MediaCodec's AAC encoder emits raw AAC access units without ADTS
     * framing — MediaRecorder.OutputFormat.AAC_ADTS used to add that for
     * us. ADTS framing is what makes each chunk file independently
     * playable/concatenable, so it's reconstructed by hand here.
     */
    private fun wrapInAdts(aac: ByteArray, sampleRate: Int, channels: Int): ByteArray {
        val frameLength = aac.size + 7
        val header = ByteArray(7)
        val freqIndex = ADTS_SAMPLE_RATES.indexOf(sampleRate).let { if (it < 0) 4 else it } // default 44100

        header[0] = 0xFF.toByte()
        header[1] = 0xF9.toByte() // MPEG-4, no CRC
        val profileBits = MediaCodecInfo.CodecProfileLevel.AACObjectLC - 1
        header[2] = ((profileBits shl 6) or (freqIndex shl 2) or (channels shr 2)).toByte()
        header[3] = (((channels and 3) shl 6) or (frameLength shr 11)).toByte()
        header[4] = ((frameLength shr 3) and 0xFF).toByte()
        header[5] = (((frameLength and 7) shl 5) or 0x1F).toByte()
        header[6] = 0xFC.toByte()

        return header + aac
    }

    private class ChunkWriter(context: Context, encoder: AudioEncoder, val chunkNumber: Int) {
        private val file: File = ChunkFileStore.newChunkFile(context, chunkNumber, extensionFor(encoder))
        private val mimeType: String = mimeTypeStringFor(encoder)
        private var stream: FileOutputStream? = null
        private val digest = MessageDigest.getInstance("SHA-256")
        private var bytesWritten = 0L

        fun open() {
            stream = FileOutputStream(file)
        }

        fun write(bytes: ByteArray) {
            stream?.write(bytes)
            digest.update(bytes)
            bytesWritten += bytes.size
        }

        fun closeAndDescribe(targetSeconds: Int): ChunkFile? {
            stream?.let { runCatching { it.flush() }; runCatching { it.close() } }
            stream = null

            if (bytesWritten <= 0) {
                runCatching { file.delete() }
                return null
            }

            val checksum = digest.digest().joinToString("") { "%02x".format(it) }
            return ChunkFile(file, chunkNumber, targetSeconds, checksum, mimeType)
        }

        companion object {
            fun extensionFor(encoder: AudioEncoder): String = when (encoder) {
                AudioEncoder.AAC -> "aac"
                AudioEncoder.FLAC -> "flac"
                AudioEncoder.OPUS -> "opus"
            }

            fun mimeTypeStringFor(encoder: AudioEncoder): String = when (encoder) {
                AudioEncoder.AAC -> "audio/aac"
                AudioEncoder.FLAC -> "audio/flac"
                AudioEncoder.OPUS -> "audio/opus"
            }
        }
    }

    companion object {
        private val ADTS_SAMPLE_RATES = listOf(96000, 88200, 64000, 48000, 44100, 32000, 24000, 22050, 16000, 12000, 11025, 8000, 7350)
    }
}
