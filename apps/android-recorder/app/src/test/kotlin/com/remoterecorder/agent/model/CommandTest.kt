package com.remoterecorder.agent.model

import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Test

class CommandTest {

    @Test
    fun `parses a RecordingStartRequested payload`() {
        val json = JSONObject().apply {
            put("command_id", "cmd-1")
            put("recording_id", "rec-1")
            put("device_id", "device-uuid")
            put("configuration", JSONObject().apply { put("encoder", "aac") })
            put("timestamp", "2026-01-01T00:00:00Z")
        }

        val command = Command.startFromJson(json)

        assertEquals("cmd-1", command.commandId)
        assertEquals(CommandType.START_RECORDING, command.type)
        assertEquals("rec-1", command.recordingId)
        assertEquals("aac", command.payload.getString("encoder"))
    }

    @Test
    fun `parses a RecordingStopRequested payload with an empty configuration`() {
        val json = JSONObject().apply {
            put("command_id", "cmd-2")
            put("recording_id", "rec-1")
            put("device_id", "device-uuid")
            put("timestamp", "2026-01-01T00:05:00Z")
        }

        val command = Command.stopFromJson(json)

        assertEquals(CommandType.STOP_RECORDING, command.type)
        assertEquals(0, command.payload.length())
    }
}
