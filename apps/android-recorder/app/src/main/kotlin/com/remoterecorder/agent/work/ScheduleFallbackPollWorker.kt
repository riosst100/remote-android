package com.remoterecorder.agent.work

import android.content.Context
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import com.remoterecorder.agent.recording.RecordingForegroundService
import com.remoterecorder.agent.recording.ScheduleAlarmPlanner
import com.remoterecorder.agent.util.AgentLog
import com.remoterecorder.agent.util.DeviceCredentialStore
import com.remoterecorder.agent.util.PendingRecordingStore
import com.remoterecorder.agent.util.ScheduleStore
import java.time.Instant
import java.time.ZoneId
import java.time.ZonedDateTime
import java.util.concurrent.TimeUnit

/**
 * Fallback path for devices that have not granted `SCHEDULE_EXACT_ALARM`
 * (see `ScheduleAlarmScheduler`). Deliberately has **no** `NetworkType`
 * constraint — this check must run offline too, since it's literally the
 * offline-tolerant path.
 *
 * Each tick does two independent checks:
 * 1. **Start**: recomputes the next occurrence from the locally cached
 *    schedule and, if it already passed within the last 15 minutes (this
 *    worker's own cadence — WorkManager's practical floor, matching
 *    HeartbeatWorker's), starts it directly. **Documented trade-off:
 *    recording may start up to 15 minutes late** in this fallback case.
 * 2. **Stop**: `armStopAlarm` is a no-op under this same fallback (there is
 *    no exact-alarm permission to schedule a precise stop with), so this
 *    tick is also the *only* thing that ever stops a schedule-triggered
 *    recording on a device without exact-alarm access — without this, a
 *    recording started via the fallback path would run forever. Any
 *    still-`RECORDING` pending entry whose `startedAt + durationMinutes`
 *    has passed gets stopped here. **Same up-to-15-minutes-late trade-off
 *    applies to stopping as to starting.**
 */
class ScheduleFallbackPollWorker(appContext: Context, params: WorkerParameters) : CoroutineWorker(appContext, params) {

    override suspend fun doWork(): Result {
        val credentials = DeviceCredentialStore(applicationContext)
        if (!credentials.isRegistered()) return Result.success()

        val schedules = ScheduleStore(applicationContext).getAll()
        val now = ZonedDateTime.now(ZoneId.of("Asia/Jakarta"))

        stopOverdueRecordings(schedules, now)

        if (schedules.isEmpty()) return Result.success()

        // Look back from "now" for the schedule that most recently should
        // have started (within this worker's own poll window), rather than
        // just the *next future* occurrence — the latter is always in the
        // future by construction (ScheduleAlarmPlanner never returns
        // something in the past), so we need the mirror check: is there an
        // occurrence in [now - POLL_WINDOW, now)?
        val windowStart = now.minusMinutes(POLL_WINDOW_MINUTES)
        val due = schedules
            .mapNotNull { schedule -> ScheduleAlarmPlanner.nextOccurrence(listOf(schedule), windowStart) }
            .filter { it.triggerAtEpochMillis <= now.toInstant().toEpochMilli() }
            .minByOrNull { it.triggerAtEpochMillis }

        if (due != null) {
            AgentLog.i("schedule_fallback_poll", "Schedule ${due.schedule.id} is due; starting via fallback poll.")
            RecordingForegroundService.startFromSchedule(applicationContext, due.schedule)
        }

        return Result.success()
    }

    /**
     * Stops any locally-started recording whose scheduled duration has
     * elapsed. Necessary because `ScheduleAlarmScheduler.armStopAlarm` is a
     * no-op when exact alarms aren't available — this poll tick is the only
     * remaining mechanism that can ever stop such a recording.
     */
    private fun stopOverdueRecordings(schedules: List<com.remoterecorder.agent.model.DeviceSchedule>, now: ZonedDateTime) {
        val scheduleById = schedules.associateBy { it.id }
        val nowMillis = now.toInstant().toEpochMilli()

        PendingRecordingStore(applicationContext).getAllPending()
            .filter { !it.finished }
            .forEach { pending ->
                val durationMinutes = pending.scheduleId?.let { scheduleById[it]?.durationMinutes } ?: return@forEach
                val stopAtMillis = Instant.ofEpochMilli(pending.startedAtEpochMillis).toEpochMilli() + durationMinutes * 60_000L

                if (nowMillis >= stopAtMillis) {
                    AgentLog.i("schedule_fallback_poll", "Recording ${pending.localId} is overdue to stop; stopping via fallback poll.")
                    RecordingForegroundService.stopFromSchedule(applicationContext, pending.localId)
                }
            }
    }

    companion object {
        private const val WORK_NAME = "schedule_fallback_poll"
        private const val POLL_WINDOW_MINUTES = 15L

        fun schedule(context: Context) {
            val request = PeriodicWorkRequestBuilder<ScheduleFallbackPollWorker>(POLL_WINDOW_MINUTES, TimeUnit.MINUTES).build()

            WorkManager.getInstance(context).enqueueUniquePeriodicWork(
                WORK_NAME,
                ExistingPeriodicWorkPolicy.KEEP,
                request,
            )
        }
    }
}
