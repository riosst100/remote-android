package com.remoterecorder.agent.boot

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import com.remoterecorder.agent.model.DeviceSchedule
import com.remoterecorder.agent.recording.RecordingForegroundService
import com.remoterecorder.agent.recording.ScheduleAlarmScheduler
import com.remoterecorder.agent.util.AgentLog
import com.remoterecorder.agent.util.ScheduleStore
import org.json.JSONObject

/**
 * Fires when an `AlarmManager` schedule alarm goes off. Separate from
 * [BootCompletedReceiver] since this is triggered by a one-shot
 * `PendingIntent`, not the boot broadcast.
 *
 * Re-arming the next alarm is done first, unconditionally, before anything
 * about the actual recording — so a failure later (e.g. mic permission
 * revoked) can never leave the device's future schedule silently un-armed.
 */
class ScheduleAlarmReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        when (intent.action) {
            ScheduleAlarmScheduler.ACTION_SCHEDULE_START -> {
                val schedules = ScheduleStore(context).getAll()
                ScheduleAlarmScheduler.rearmNext(context, schedules)

                val scheduleJson = intent.getStringExtra(ScheduleAlarmScheduler.EXTRA_SCHEDULE_JSON)
                val schedule = scheduleJson?.let { runCatching { DeviceSchedule.fromJson(JSONObject(it)) }.getOrNull() }
                if (schedule == null) {
                    AgentLog.e("schedule_alarm", "Received SCHEDULE_START with missing/malformed schedule payload; ignoring.")
                    return
                }

                RecordingForegroundService.startFromSchedule(context, schedule)
            }
            ScheduleAlarmScheduler.ACTION_SCHEDULE_STOP -> {
                val recordingId = intent.getStringExtra(ScheduleAlarmScheduler.EXTRA_RECORDING_ID)
                if (recordingId == null) {
                    AgentLog.e("schedule_alarm", "Received SCHEDULE_STOP with no recording id; ignoring.")
                    return
                }
                RecordingForegroundService.stopFromSchedule(context, recordingId)
            }
        }
    }
}
