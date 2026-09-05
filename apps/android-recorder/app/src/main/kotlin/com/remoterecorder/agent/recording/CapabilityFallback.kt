package com.remoterecorder.agent.recording

import android.media.AudioFormat
import android.media.AudioRecord
import android.media.MediaCodecInfo
import android.media.MediaCodecList
import com.remoterecorder.agent.model.AudioEncoder
import com.remoterecorder.agent.model.RecordingConfig
import com.remoterecorder.agent.util.AgentLog

/**
 * Confirms whether a proposed [RecordingConfig] actually works on this
 * hardware and, if not, walks a conservative fallback ladder down to
 * something that does. Laravel's proposal is only ever a suggestion —
 * this is the last word on what configuration is actually used, since
 * capability data reported at registration time can be incomplete or the
 * device's runtime state can differ (e.g. another app has the mic HAL busy
 * at a certain sample rate).
 */
object CapabilityFallback {

    private data class Rung(val sampleRate: Int, val bitrate: Int, val channels: Int, val encoder: AudioEncoder)

    private val FALLBACK_LADDER = listOf(
        Rung(48000, 256_000, 1, AudioEncoder.AAC),
        Rung(44100, 192_000, 1, AudioEncoder.AAC),
        Rung(44100, 128_000, 1, AudioEncoder.AAC),
        Rung(22050, 64_000, 1, AudioEncoder.AAC),
        Rung(16000, 32_000, 1, AudioEncoder.AAC),
    )

    /**
     * Returns a config guaranteed to pass [isSupported], preferring the
     * originally requested one. Never throws — falls back to the most
     * conservative rung if literally nothing else works, since we must
     * never crash because a requested configuration is unsupported.
     */
    fun resolve(requested: RecordingConfig): RecordingConfig {
        if (isSupported(requested)) return requested

        AgentLog.w("capabilities", "Requested config unsupported ($requested), falling back.")

        for (rung in FALLBACK_LADDER) {
            val candidate = RecordingConfig(rung.encoder, rung.sampleRate, rung.bitrate, rung.channels)
            if (isSupported(candidate)) return candidate
        }

        // Last resort: the most conservative rung, regardless of whether our
        // own check passed — AudioRecord.getMinBufferSize can be overly
        // strict on some OEM HALs, and refusing to record at all is worse.
        val last = FALLBACK_LADDER.last()
        return RecordingConfig(last.encoder, last.sampleRate, last.bitrate, last.channels)
    }

    fun isSupported(config: RecordingConfig): Boolean {
        val channelConfig = if (config.channels >= 2) AudioFormat.CHANNEL_IN_STEREO else AudioFormat.CHANNEL_IN_MONO
        val minBuffer = AudioRecord.getMinBufferSize(config.sampleRate, channelConfig, AudioFormat.ENCODING_PCM_16BIT)
        if (minBuffer <= 0) return false

        return encoderAvailable(config.encoder)
    }

    private fun encoderAvailable(encoder: AudioEncoder): Boolean {
        val mimeType = when (encoder) {
            AudioEncoder.AAC -> "audio/mp4a-latm"
            AudioEncoder.OPUS -> "audio/opus"
            AudioEncoder.FLAC -> "audio/flac"
        }

        if (encoder == AudioEncoder.AAC) return true // MediaRecorder AAC path always available

        val codecList = MediaCodecList(MediaCodecList.REGULAR_CODECS)
        return codecList.codecInfos.any { info: MediaCodecInfo ->
            info.isEncoder && info.supportedTypes.any { it.equals(mimeType, ignoreCase = true) }
        }
    }
}
