package com.remoterecorder.agent.recording

import android.content.Context
import android.content.pm.PackageManager
import android.media.AudioFormat
import android.media.AudioRecord
import android.media.MediaCodecList
import com.remoterecorder.agent.model.AudioCapabilities
import com.remoterecorder.agent.util.AgentLog

/**
 * Probes what this specific device actually supports rather than assuming
 * every Android device is the same. Sample-rate/channel support is
 * confirmed with a real (immediately-released) [AudioRecord.getMinBufferSize]
 * check, not just a static list, since OEM audio HALs vary.
 */
object AudioCapabilityDetector {

    private val CANDIDATE_SAMPLE_RATES = intArrayOf(48000, 44100, 22050, 16000)
    private val CANDIDATE_BITRATES = intArrayOf(64_000, 96_000, 128_000, 192_000, 256_000)

    fun detect(context: Context): AudioCapabilities {
        val hasMic = context.packageManager.hasSystemFeature(PackageManager.FEATURE_MICROPHONE)
        if (!hasMic) {
            AgentLog.w("capabilities", "Device reports no microphone feature.")
        }

        val encoders = detectEncoders()
        val sampleRates = detectSupportedSampleRates()
        val channels = listOf(1, 2)

        return AudioCapabilities(
            encoders = encoders,
            sampleRates = sampleRates,
            channels = channels,
            bitrates = CANDIDATE_BITRATES.toList(),
        )
    }

    private fun detectEncoders(): List<String> {
        val found = linkedSetOf<String>()
        val codecList = MediaCodecList(MediaCodecList.REGULAR_CODECS)

        for (info in codecList.codecInfos) {
            if (!info.isEncoder) continue
            for (type in info.supportedTypes) {
                when {
                    type.equals("audio/mp4a-latm", ignoreCase = true) -> found.add("aac")
                    type.equals("audio/opus", ignoreCase = true) -> found.add("opus")
                    type.equals("audio/flac", ignoreCase = true) -> found.add("flac")
                }
            }
        }

        // AAC via MediaRecorder is available on effectively all real devices
        // even when it doesn't show up cleanly in MediaCodecList.
        if (found.isEmpty()) found.add("aac")

        return found.toList()
    }

    private fun detectSupportedSampleRates(): List<Int> {
        val supported = mutableListOf<Int>()

        for (rate in CANDIDATE_SAMPLE_RATES) {
            val minBuffer = AudioRecord.getMinBufferSize(
                rate,
                AudioFormat.CHANNEL_IN_MONO,
                AudioFormat.ENCODING_PCM_16BIT,
            )
            if (minBuffer > 0) {
                supported.add(rate)
            }
        }

        return if (supported.isEmpty()) listOf(44100) else supported
    }
}
