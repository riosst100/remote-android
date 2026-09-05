package com.remoterecorder.agent.work

import android.content.Context
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import com.remoterecorder.agent.network.ApiClient
import com.remoterecorder.agent.recording.RecordingForegroundService
import com.remoterecorder.agent.util.AgentLog
import com.remoterecorder.agent.util.DeviceCredentialStore
import java.util.concurrent.TimeUnit

/**
 * Periodic HTTP heartbeat, independent of the WebSocket connection. Two
 * signals for "is this device alive" gives the server a reliable OFFLINE
 * detection path even if the socket is having trouble reconnecting, and
 * gives the device a way to recover its foreground service if the process
 * was killed and WorkManager is the only thing still scheduled.
 */
class HeartbeatWorker(appContext: Context, params: WorkerParameters) : CoroutineWorker(appContext, params) {

    override suspend fun doWork(): Result {
        val credentials = DeviceCredentialStore(applicationContext)
        if (!credentials.isRegistered()) return Result.success()

        val client = ApiClient { credentials.token }

        return try {
            client.heartbeat(status = null)
            RecordingForegroundService.ensureRunning(applicationContext)
            Result.success()
        } catch (e: Exception) {
            AgentLog.w("heartbeat", "Heartbeat failed, will retry on next schedule.", e)
            Result.success() // periodic work should not accumulate retries; next tick will try again
        }
    }

    companion object {
        private const val WORK_NAME = "device_heartbeat"

        fun schedule(context: Context) {
            val constraints = Constraints.Builder()
                .setRequiredNetworkType(NetworkType.CONNECTED)
                .build()

            val request = PeriodicWorkRequestBuilder<HeartbeatWorker>(15, TimeUnit.MINUTES)
                .setConstraints(constraints)
                .build()

            WorkManager.getInstance(context).enqueueUniquePeriodicWork(
                WORK_NAME,
                ExistingPeriodicWorkPolicy.KEEP,
                request,
            )
        }
    }
}
