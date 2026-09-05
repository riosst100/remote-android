package com.remoterecorder.agent.model

import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Test

class RecordingConfigTest {

    @Test
    fun `parses a full command payload`() {
        val payload = JSONObject().apply {
            put("encoder", "aac")
            put("sample_rate", 48000)
            put("bitrate", 256000)
            put("channels", 1)
        }

        val config = RecordingConfig.fromCommandPayload(payload)

        assertEquals(AudioEncoder.AAC, config.encoder)
        assertEquals(48000, config.sampleRate)
        assertEquals(256000, config.bitrate)
        assertEquals(1, config.channels)
    }

    @Test
    fun `falls back to sensible defaults when fields are missing`() {
        val config = RecordingConfig.fromCommandPayload(JSONObject())

        assertEquals(AudioEncoder.AAC, config.encoder)
        assertEquals(44100, config.sampleRate)
        assertEquals(128_000, config.bitrate)
        assertEquals(1, config.channels)
    }

    @Test
    fun `falls back to AAC for an unrecognized encoder name instead of throwing`() {
        val payload = JSONObject().apply { put("encoder", "mystery-codec") }

        val config = RecordingConfig.fromCommandPayload(payload)

        assertEquals(AudioEncoder.AAC, config.encoder)
    }

    @Test
    fun `round trips through toJson`() {
        val config = RecordingConfig(AudioEncoder.OPUS, 44100, 96000, 2)
        val json = config.toJson()

        assertEquals("opus", json.getString("encoder"))
        assertEquals(44100, json.getInt("sample_rate"))
        assertEquals(96000, json.getInt("bitrate"))
        assertEquals(2, json.getInt("channels"))
    }
}
