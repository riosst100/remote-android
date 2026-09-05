package com.remoterecorder.agent.model

import org.json.JSONArray
import org.json.JSONObject

/**
 * Snapshot of what this device's audio stack can actually do, gathered at
 * registration time from [android.media.MediaCodecList] and
 * [android.media.AudioRecord] probing. Sent to Laravel so it can propose
 * sensible recording configurations instead of guessing.
 */
data class AudioCapabilities(
    val encoders: List<String>,
    val sampleRates: List<Int>,
    val channels: List<Int>,
    val bitrates: List<Int>,
) {
    fun toJson(): JSONObject = JSONObject().apply {
        put("encoders", JSONArray(encoders))
        put("sample_rates", JSONArray(sampleRates))
        put("channels", JSONArray(channels))
        put("bitrates", JSONArray(bitrates))
    }
}
