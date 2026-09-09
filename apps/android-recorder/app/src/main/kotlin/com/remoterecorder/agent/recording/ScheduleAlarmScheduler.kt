package com.remoterecorder.agent.recording

import android.app.AlarmManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.os.Build
import com.remoterecorder.agent.boot.ScheduleAlarmReceiver
import com.remoterecorder.agent.model.DeviceSchedule
import com.remoterecorder.agent.util.AgentLog
import com.remoterecorder.agent.work.ScheduleFallbackPollWorker
import java.time.ZoneId
import java.time.ZonedDateTime

/**
 * Thin wrapper around [AlarmManager] for schedule-triggered start/stop.
 * Picks between an exact `AlarmManager` alarm and a `WorkManager` fallback
 * poll depending on whether `SCHEDULE_EXACT_ALARM` is currently granted —
 * checked fresh on every call, so a user granting the permission later is
 * picked up on the next sync/boot without an app restart.
 */
object ScheduleAlarmScheduler {

    private const val REQUEST_CODE_START = 1001
    private const val REQUEST_CODE_STOP = 1002

    const val ACTION_SCHEDULE_START = "com.remoterecorder.agent.action.SCHEDULE_START"
    const val ACTION_SCHEDULE_STOP = "com.remoterecorder.agent.action.SCHEDULE_STOP"
    const val EXTRA_SCHEDULE_JSON = "schedule_json"
    const val EXTRA_RECORDING_ID = "recording_id"

    /** Re-arms the single next occurrence across all [schedules] (alarms are one-shot). */
    fun rearmNext(context: Context, schedules: List<DeviceSchedule>) {
        val now = ZonedDateTime.now(ZoneId.of("Asia/Jakarta"))
        val next = ScheduleAlarmPlanner.nextOccurrence(schedules, now) ?: run {
            AgentLog.i("schedule_alarm", "No schedules to arm.")
            return
        }
        armStartAlarm(context, next.schedule, next.triggerAtEpochMillis)
    }

    fun armStartAlarm(context: Context, schedule: DeviceSchedule, triggerAtEpochMillis: Long) {
        if (canScheduleExactAlarms(context)) {
            val intent = Intent(context, ScheduleAlarmReceiver::class.java).apply {
                action = ACTION_SCHEDULE_START
                putExtra(EXTRA_SCHEDULE_JSON, schedule.toJson().toString())
            }
            val pendingIntent = PendingIntent.getBroadcast(
                context,
                REQUEST_CODE_START,
                intent,
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
            )
            val alarmManager = context.getSystemService(AlarmManager::class.java)
            alarmManager.setExactAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, triggerAtEpochMillis, pendingIntent)
            AgentLog.i("schedule_alarm", "Armed exact start alarm for schedule ${schedule.id} at $triggerAtEpochMillis.")
        } else {
            AgentLog.i("schedule_alarm", "Exact alarms unavailable; relying on ScheduleFallbackPollWorker.")
            ScheduleFallbackPollWorker.schedule(context)
        }
    }

    fun armStopAlarm(context: Context, recordingLocalId: String, triggerAtEpochMillis: Long) {
        if (canScheduleExactAlarms(context)) {
            val intent = Intent(context, ScheduleAlarmReceiver::class.java).apply {
                action = ACTION_SCHEDULE_STOP
                putExtra(EXTRA_RECORDING_ID, recordingLocalId)
            }
            val pendingIntent = PendingIntent.getBroadcast(
                context,
                REQUEST_CODE_STOP,
                intent,
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
            )
            val alarmManager = context.getSystemService(AlarmManager::class.java)
            alarmManager.setExactAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, triggerAtEpochMillis, pendingIntent)
            AgentLog.i("schedule_alarm", "Armed exact stop alarm for $recordingLocalId at $triggerAtEpochMillis.")
        }
        // No alarm armed when exact alarms are unavailable — there is
        // nothing to fall back to *here* (no pendingIntent fires without
        // AlarmManager). Instead, ScheduleFallbackPollWorker's own periodic
        // tick is what stops an overdue recording in that case (see its
        // stopOverdueRecordings, which reads each pending recording's
        // startedAt + durationMinutes directly) — this method intentionally
        // does nothing beyond the exact-alarm branch above.
    }

    private fun canScheduleExactAlarms(context: Context): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.S) return true // no restriction before API 31
        val alarmManager = context.getSystemService(AlarmManager::class.java)
        return alarmManager.canScheduleExactAlarms()
    }
}
