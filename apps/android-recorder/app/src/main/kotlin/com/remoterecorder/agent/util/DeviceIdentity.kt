package com.remoterecorder.agent.util

import android.content.Context
import java.util.UUID

/**
 * Persistent device identifier. Generated once on first run and stored in
 * [SecurePrefs], deliberately independent of hardware identifiers (IMEI,
 * Android ID, serial) since those can change across factory resets, SIM
 * swaps, or simply aren't reliably available without extra permissions.
 */
object DeviceIdentity {

    private const val KEY_DEVICE_UUID = "device_uuid"

    fun getOrCreate(context: Context): String {
        val prefs = SecurePrefs.get(context)
        prefs.getString(KEY_DEVICE_UUID, null)?.let { return it }

        val newUuid = UUID.randomUUID().toString()
        prefs.edit().putString(KEY_DEVICE_UUID, newUuid).apply()
        return newUuid
    }
}
