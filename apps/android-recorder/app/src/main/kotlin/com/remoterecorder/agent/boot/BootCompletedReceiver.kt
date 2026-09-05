package com.remoterecorder.agent.boot

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import com.remoterecorder.agent.recording.RecordingForegroundService
import com.remoterecorder.agent.util.AgentLog
import com.remoterecorder.agent.util.DeviceCredentialStore
import com.remoterecorder.agent.work.HeartbeatWorker

/**
 * On boot, reconnects the agent to the control plane so it can receive
 * new commands and reports its status — it does NOT resume or start any
 * microphone recording on its own. Recording only ever starts in response
 * to an explicit START_RECORDING command received while the service is
 * running, never automatically. If a recording was in progress when the
 * device rebooted, that recording is left in its last known server-side
 * state; the admin can start a fresh one from the dashboard.
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
    }
}
