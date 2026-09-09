package com.remoterecorder.agent.model

import org.json.JSONObject

enum class AudioEncoder { AAC, FLAC, OPUS }

/**
 * The audio configuration a recording session actually runs with. Starts
 * out as the server's *proposed* configuration (from the START_RECORDING
 * command payload) and is adjusted locally via [CapabilityFallback] if the
 * device can't actually support it — the server is told what was really
 * used via the recording_started acknowledgement.
 */
data class RecordingConfig(
    val encoder: AudioEncoder,
    val sampleRate: Int,
    val bitrate: Int,
    val channels: Int,
) {
    fun toJson(): JSONObject = JSONObject().apply {
        put("encoder", encoder.name.lowercase())
        put("sample_rate", sampleRate)
        put("bitrate", bitrate)
        put("channels", channels)
    }

    companion object {
        fun fromCommandPayload(payload: JSONObject): RecordingConfig {
            val encoderName = payload.optString("encoder", "aac").uppercase()
            val encoder = runCatching { AudioEncoder.valueOf(encoderName) }.getOrDefault(AudioEncoder.AAC)

            return RecordingConfig(
                encoder = encoder,
                sampleRate = payload.optInt("sample_rate", 44100),
                bitrate = payload.optInt("bitrate", 128_000),
                channels = payload.optInt("channels", 1),
            )
        }

        /**
         * Maps a schedule's LOW/MEDIUM/HIGH preset to a starting-point
         * config, matching rungs from [com.remoterecorder.agent.recording.CapabilityFallback]'s
         * own ladder philosophy. This is only ever a *proposal* — the caller
         * (RecordingSessionManager.startFromSchedule) passes the result
         * through `CapabilityFallback.resolve(...)` exactly as the
         * WebSocket-triggered `handleStart` path already does, so this
         * function doesn't need to itself validate hardware support.
         */
        fun fromPreset(preset: String): RecordingConfig = when (preset.uppercase()) {
            "HIGH" -> RecordingConfig(AudioEncoder.AAC, 48000, 256_000, 1)
            "LOW" -> RecordingConfig(AudioEncoder.AAC, 22050, 64_000, 1)
            else -> RecordingConfig(AudioEncoder.AAC, 44100, 128_000, 1) // MEDIUM and any unrecognized value
        }
    }
}
