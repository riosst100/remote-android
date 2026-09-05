package com.remoterecorder.agent.util

import com.remoterecorder.agent.BuildConfig

object AppConfig {
    val apiBaseUrl: String = BuildConfig.API_BASE_URL.trimEnd('/')
    val wsHost: String = BuildConfig.WS_HOST
    val wsPort: Int = BuildConfig.WS_PORT
    val wsTls: Boolean = BuildConfig.WS_TLS
    val reverbAppKey: String = BuildConfig.REVERB_APP_KEY
    val appVersion: String = BuildConfig.VERSION_NAME

    val wsUrl: String
        get() {
            val scheme = if (wsTls) "wss" else "ws"
            return "$scheme://$wsHost:$wsPort/app/$reverbAppKey?protocol=7&client=android-agent&version=1.0"
        }

    val broadcastAuthUrl: String
        get() = "$apiBaseUrl/api/devices/broadcasting/auth"
}
