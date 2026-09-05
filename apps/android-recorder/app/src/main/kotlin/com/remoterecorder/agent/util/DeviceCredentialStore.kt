package com.remoterecorder.agent.util

import android.content.Context

/**
 * Holds the device's API bearer token, issued by Laravel at registration
 * time and rotated on every re-registration. Never bundled in the APK or
 * hardcoded — this is the only place it lives on-device.
 */
class DeviceCredentialStore(context: Context) {

    private val prefs = SecurePrefs.get(context)

    var token: String?
        get() = prefs.getString(KEY_TOKEN, null)
        set(value) = prefs.edit().putString(KEY_TOKEN, value).apply()

    var registeredAppVersion: String?
        get() = prefs.getString(KEY_APP_VERSION, null)
        set(value) = prefs.edit().putString(KEY_APP_VERSION, value).apply()

    /** The server-assigned numeric device id, needed to subscribe to this device's private channel. */
    var serverDeviceId: Long
        get() = prefs.getLong(KEY_SERVER_DEVICE_ID, -1L)
        set(value) = prefs.edit().putLong(KEY_SERVER_DEVICE_ID, value).apply()

    fun isRegistered(): Boolean = !token.isNullOrEmpty() && serverDeviceId > 0

    fun clear() {
        prefs.edit().remove(KEY_TOKEN).remove(KEY_SERVER_DEVICE_ID).apply()
    }

    companion object {
        private const val KEY_TOKEN = "device_api_token"
        private const val KEY_APP_VERSION = "registered_app_version"
        private const val KEY_SERVER_DEVICE_ID = "server_device_id"
    }
}
