package com.remoterecorder.agent.network

import android.content.Context
import android.os.Build
import com.remoterecorder.agent.recording.AudioCapabilityDetector
import com.remoterecorder.agent.util.AgentLog
import com.remoterecorder.agent.util.AppConfig
import com.remoterecorder.agent.util.DeviceCredentialStore
import com.remoterecorder.agent.util.DeviceIdentity

class DeviceRegistrationManager(private val context: Context) {

    private val credentials = DeviceCredentialStore(context)
    private val apiClient = ApiClient { credentials.token }

    fun isRegistered(): Boolean = credentials.isRegistered()

    /**
     * Registers (or re-registers) this device with Laravel. Safe to call
     * again later — e.g. after an app update — since the server treats
     * registration as an upsert keyed on device_uuid and rotates the token.
     */
    fun register(): Result<Unit> {
        val deviceUuid = DeviceIdentity.getOrCreate(context)
        val capabilities = AudioCapabilityDetector.detect(context)

        return runCatching {
            val response = apiClient.registerDevice(
                deviceUuid = deviceUuid,
                name = Build.MODEL,
                manufacturer = Build.MANUFACTURER,
                model = Build.MODEL,
                androidVersion = Build.VERSION.RELEASE ?: Build.VERSION.SDK_INT.toString(),
                appVersion = AppConfig.appVersion,
                capabilities = capabilities,
            )

            val device = response.getJSONObject("device")
            credentials.token = response.getString("token")
            credentials.serverDeviceId = device.getLong("id")
            credentials.registeredAppVersion = AppConfig.appVersion

            AgentLog.i("registration", "Device registered as id=${device.getLong("id")}.")
            Unit
        }.onFailure {
            AgentLog.e("registration", "Device registration failed.", it)
        }
    }
}
