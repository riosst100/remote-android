package com.remoterecorder.agent.model

import org.json.JSONObject

enum class CommandType { START_RECORDING, STOP_RECORDING }

/**
 * A control-plane command as delivered over the WebSocket. `commandId` is
 * the idempotency key: every ack (and every dedupe check before acting)
 * is keyed on it.
 */
data class Command(
    val commandId: String,
    val type: CommandType,
    val recordingId: String,
    val deviceId: String,
    val payload: JSONObject,
    val timestamp: String,
) {
    companion object {
        fun startFromJson(json: JSONObject): Command = Command(
            commandId = json.getString("command_id"),
            type = CommandType.START_RECORDING,
            recordingId = json.getString("recording_id"),
            deviceId = json.getString("device_id"),
            payload = json.optJSONObject("configuration") ?: JSONObject(),
            timestamp = json.optString("timestamp"),
        )

        fun stopFromJson(json: JSONObject): Command = Command(
            commandId = json.getString("command_id"),
            type = CommandType.STOP_RECORDING,
            recordingId = json.getString("recording_id"),
            deviceId = json.getString("device_id"),
            payload = JSONObject(),
            timestamp = json.optString("timestamp"),
        )
    }
}
