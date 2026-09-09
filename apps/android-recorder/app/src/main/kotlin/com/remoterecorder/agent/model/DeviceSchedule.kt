package com.remoterecorder.agent.model

import org.json.JSONObject

/**
 * The device's local copy of one `RecordingSchedule` row, as returned by
 * `GET /api/devices/schedules` (`DeviceScheduleResource`). This is the
 * authoritative shape the device plans alarms against — see
 * `recording/ScheduleAlarmPlanner.kt`.
 */
data class DeviceSchedule(
    val id: Long,
    val dayOfWeek: Int, // 0=Sunday..6=Saturday, matches Carbon::dayOfWeek server-side
    val timeOfDay: String, // "HH:mm:ss", Asia/Jakarta wall-clock, as returned by DeviceScheduleResource
    val preset: String, // LOW/MEDIUM/HIGH
    val durationMinutes: Int,
) {
    fun toJson(): JSONObject = JSONObject().apply {
        put("id", id)
        put("day_of_week", dayOfWeek)
        put("time_of_day", timeOfDay)
        put("preset", preset)
        put("duration_minutes", durationMinutes)
    }

    companion object {
        fun fromJson(json: JSONObject): DeviceSchedule = DeviceSchedule(
            id = json.getLong("id"),
            dayOfWeek = json.getInt("day_of_week"),
            timeOfDay = json.getString("time_of_day"),
            preset = json.getString("preset"),
            durationMinutes = json.getInt("duration_minutes"),
        )
    }
}
