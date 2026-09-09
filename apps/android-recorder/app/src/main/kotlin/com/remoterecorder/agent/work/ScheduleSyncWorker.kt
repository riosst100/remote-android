package com.remoterecorder.agent.work

import android.content.Context
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import com.remoterecorder.agent.model.DeviceSchedule
import com.remoterecorder.agent.network.ApiClient
import com.remoterecorder.agent.recording.ScheduleAlarmScheduler
import com.remoterecorder.agent.util.AgentLog
import com.remoterecorder.agent.util.DeviceCredentialStore
import com.remoterecorder.agent.util.ScheduleStore
import org.json.JSONObject
import java.util.concurrent.TimeUnit

/**
 * Periodically re-syncs the device's own schedule list from
 * `GET /api/devices/schedules` and re-arms the next alarm from the fresh
 * copy. 30 minutes (vs. HeartbeatWorker's 15) is intentional: schedule
 * definitions change rarely, so this doesn't need heartbeat-grade
 * freshness — but it's still the mechanism that recovers a device's local
 * schedule copy after being offline for hours, and it's also the natural
 * place to re-arm alarms after any schedule sync (covering "admin edited a
 * schedule while device was reachable", distinct from the boot-time re-arm).
 */
class ScheduleSyncWorker(appContext: Context, params: WorkerParameters) : CoroutineWorker(appContext, params) {

    override suspend fun doWork(): Result {
        val credentials = DeviceCredentialStore(applicationContext)
        if (!credentials.isRegistered()) return Result.success()

        val client = ApiClient { credentials.token }

        return try {
            val json = client.fetchSchedules()
            val schedules = parseSchedules(json)
            ScheduleStore(applicationContext).replaceAll(schedules)
            ScheduleAlarmScheduler.rearmNext(applicationContext, schedules)
            Result.success()
        } catch (e: Exception) {
            AgentLog.w("schedule_sync", "Schedule sync failed, will retry next tick.", e)
            Result.success() // periodic work should not accumulate retries; next tick will try again
        }
    }

    private fun parseSchedules(json: JSONObject): List<DeviceSchedule> {
        val array = json.optJSONArray("data") ?: return emptyList()
        return (0 until array.length()).mapNotNull { index ->
            runCatching { DeviceSchedule.fromJson(array.getJSONObject(index)) }.getOrNull()
        }
    }

    companion object {
        private const val WORK_NAME = "schedule_sync"

        fun schedule(context: Context) {
            val constraints = Constraints.Builder()
                .setRequiredNetworkType(NetworkType.CONNECTED)
                .build()

            val request = PeriodicWorkRequestBuilder<ScheduleSyncWorker>(30, TimeUnit.MINUTES)
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
