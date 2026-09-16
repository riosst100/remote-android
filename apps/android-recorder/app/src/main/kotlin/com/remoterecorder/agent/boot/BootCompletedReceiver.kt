package com.remoterecorder.agent.boot

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import com.remoterecorder.agent.recording.RecordingForegroundService
import com.remoterecorder.agent.recording.ScheduleAlarmScheduler
import com.remoterecorder.agent.util.AgentLog
import com.remoterecorder.agent.util.DeviceCredentialStore
import com.remoterecorder.agent.util.ScheduleStore
import com.remoterecorder.agent.work.HeartbeatWorker
import com.remoterecorder.agent.work.PendingRecordingSyncWorker
import com.remoterecorder.agent.work.ScheduleFallbackPollWorker
import com.remoterecorder.agent.work.ScheduleSyncWorker

/**
 * On boot, reconnects the agent to the control plane so it can receive
 * new commands and reports its status — it does NOT resume or start any
 * microphone recording on its own. Recording only ever starts in response
 * to an explicit START_RECORDING command received while the service is
 * running (admin "start now"), or to a schedule-triggered alarm firing
 * from the device's own locally synced copy of its recording schedule —
 * never automatically as a side effect of booting. If a recording was in
 * progress when the device rebooted, that recording is left in its last
 * known server-side state; the admin can start a fresh one from the
 * dashboard.
 *
 * The one schedule-related thing boot *does* do is re-arm the next
 * *future* schedule alarm (using whatever ScheduleStore already has
 * cached locally — no network call needed) and re-affirm the periodic
 * schedule/pending-recording sync jobs survive the reboot. This is not
 * "resuming a recording": it only ensures a schedule that would have
 * fired while the device was off is still armed for its next occurrence.
 */
class BootCompletedReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != Intent.ACTION_BOOT_COMPLETED) return

        val credentials = DeviceCredentialStore(context)
        if (!credentials.isRegistered()) {
            AgentLog.i("boot", "Device not registered yet; skipping post-boot reconnect.")
            return
        }

        AgentLog.i("boot", "Boot completed; restarting agent service and heartbeat schedule.")
        RecordingForegroundService.ensureRunning(context)
        HeartbeatWorker.schedule(context)

        val schedules = ScheduleStore(context).getAll()
        ScheduleAlarmScheduler.rearmNext(context, schedules)
        ScheduleSyncWorker.schedule(context) // re-affirm periodic sync survives reboot; idempotent (KEEP policy)
        ScheduleFallbackPollWorker.schedule(context) // same: re-affirm, idempotent (KEEP policy)
        PendingRecordingSyncWorker.schedule(context)
    }
}
