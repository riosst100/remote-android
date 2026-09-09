package com.remoterecorder.agent.util

import android.content.Context
import com.remoterecorder.agent.model.DeviceSchedule
import org.json.JSONArray

/**
 * Local cache of the device's own recording schedule, synced periodically
 * from `GET /api/devices/schedules` (see `work/ScheduleSyncWorker.kt`).
 * Each sync is a full replacement — no partial merge/diffing — since the
 * server always returns the complete active list and the payload is tiny.
 *
 * Reuses the same `SecurePrefs`-backed pattern as `DeviceCredentialStore`:
 * schedule times aren't secret, but sharing the one storage mechanism
 * avoids introducing a second (e.g. Room) dependency for a small flat list.
 */
class ScheduleStore(context: Context) {

    private val prefs = SecurePrefs.get(context)

    fun replaceAll(schedules: List<DeviceSchedule>) {
        val array = JSONArray().apply { schedules.forEach { put(it.toJson()) } }
        prefs.edit()
            .putString(KEY_SCHEDULES, array.toString())
            .putLong(KEY_LAST_SYNCED_AT, System.currentTimeMillis())
            .apply()
    }

    fun getAll(): List<DeviceSchedule> {
        val raw = prefs.getString(KEY_SCHEDULES, null) ?: return emptyList()
        return runCatching {
            val array = JSONArray(raw)
            (0 until array.length()).mapNotNull { index ->
                runCatching { DeviceSchedule.fromJson(array.getJSONObject(index)) }.getOrNull()
            }
        }.getOrDefault(emptyList())
    }

    val lastSyncedAtMillis: Long
        get() = prefs.getLong(KEY_LAST_SYNCED_AT, -1L)

    companion object {
        private const val KEY_SCHEDULES = "device_schedules_json"
        private const val KEY_LAST_SYNCED_AT = "device_schedules_synced_at"
    }
}
