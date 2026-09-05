package com.remoterecorder.agent.util

import android.util.Log

/**
 * Thin wrapper so every log call goes through one place (structured tag,
 * easy to redirect to a file/analytics sink later) and so we have one spot
 * that guarantees tokens/secrets are never accidentally logged.
 */
object AgentLog {
    private const val TAG = "RemoteRecorderAgent"

    fun d(category: String, message: String) = Log.d(TAG, "[$category] $message")
    fun i(category: String, message: String) = Log.i(TAG, "[$category] $message")
    fun w(category: String, message: String, throwable: Throwable? = null) = Log.w(TAG, "[$category] $message", throwable)
    fun e(category: String, message: String, throwable: Throwable? = null) = Log.e(TAG, "[$category] $message", throwable)
}
