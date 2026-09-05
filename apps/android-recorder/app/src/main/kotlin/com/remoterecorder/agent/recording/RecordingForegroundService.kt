package com.remoterecorder.agent.recording

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.os.Build
import android.os.IBinder
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat
import com.remoterecorder.agent.R
import com.remoterecorder.agent.model.Command
import com.remoterecorder.agent.network.ApiClient
import com.remoterecorder.agent.network.ConnectionStatus
import com.remoterecorder.agent.network.ReverbSocketClient
import com.remoterecorder.agent.util.AgentLog
import com.remoterecorder.agent.util.DeviceCredentialStore
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * The one long-lived component of the agent. Runs as a foreground service
 * with the "microphone" type so Android keeps it (and any active
 * recording) alive with the screen off or the device locked, and so the
 * system shows the required "recording in progress" indicator.
 *
 * Deliberately owns *both* the always-on WebSocket connection and the
 * recording session, but keeps them only loosely coupled: losing the
 * WebSocket never stops an in-progress recording (see
 * RecordingSessionManager), it only means new START/STOP commands can't
 * arrive until it reconnects.
 */
class RecordingForegroundService : Service() {

    private lateinit var apiClient: ApiClient
    private lateinit var socketClient: ReverbSocketClient
    private lateinit var sessionManager: RecordingSessionManager
    private lateinit var credentials: DeviceCredentialStore
    private val serviceScope = CoroutineScope(Dispatchers.IO + Job())
    private var heartbeatJob: Job? = null

    override fun onCreate() {
        super.onCreate()
        credentials = DeviceCredentialStore(this)
        apiClient = ApiClient { credentials.token }
        sessionManager = RecordingSessionManager(this, apiClient)

        socketClient = ReverbSocketClient(
            tokenProvider = { credentials.token },
            onCommandEvent = ::onCommandEvent,
            onStatusChanged = ::onConnectionStatusChanged,
        )

        createNotificationChannels()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        startForegroundWithNotification(recording = false)

        val serverDeviceId = credentials.serverDeviceId
        if (serverDeviceId <= 0) {
            AgentLog.e("service", "Device is not registered yet (no server device id); cannot subscribe for commands.")
            return START_STICKY
        }

        socketClient.connect("private-devices.$serverDeviceId")
        startHeartbeatLoop()

        return START_STICKY
    }

    override fun onDestroy() {
        heartbeatJob?.cancel()
        socketClient.disconnect()
        super.onDestroy()
    }

    /**
     * A tighter-cadence heartbeat than WorkManager's 15-minute floor allows,
     * so the server's ~90s OFFLINE timeout is meaningful while the service
     * is alive. WorkManager's own periodic heartbeat (see HeartbeatWorker)
     * is the fallback that keeps working if the process/service gets
     * killed outright.
     */
    private fun startHeartbeatLoop() {
        heartbeatJob?.cancel()
        heartbeatJob = serviceScope.launch {
            while (true) {
                runCatching {
                    apiClient.heartbeat(status = if (sessionManager.isRecording()) "RECORDING" else null)
                }.onFailure { AgentLog.w("service", "Heartbeat failed", it) }
                delay(45_000)
            }
        }
    }

    override fun onBind(intent: Intent?): IBinder? = null

    private fun onCommandEvent(channel: String, event: String, data: org.json.JSONObject) {
        AgentLog.i("service", "Event $event on $channel")

        val command = when (event) {
            "RecordingStartRequested" -> runCatching { Command.startFromJson(data) }.getOrNull()
            "RecordingStopRequested" -> runCatching { Command.stopFromJson(data) }.getOrNull()
            else -> null
        } ?: return

        sessionManager.handleCommand(command)
        updateNotification(recording = sessionManager.isRecording())
    }

    private fun onConnectionStatusChanged(status: ConnectionStatus) {
        AgentLog.i("service", "WebSocket status: $status")
    }

    private fun startForegroundWithNotification(recording: Boolean) {
        val notification = buildNotification(recording)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            startForeground(NOTIFICATION_ID, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_MICROPHONE)
        } else {
            startForeground(NOTIFICATION_ID, notification)
        }
    }

    private fun updateNotification(recording: Boolean) {
        val manager = getSystemService(NotificationManager::class.java)
        manager.notify(NOTIFICATION_ID, buildNotification(recording))
    }

    private fun buildNotification(recording: Boolean): Notification {
        val text = if (recording) {
            getString(R.string.notification_recording_text)
        } else {
            "Connected, waiting for commands"
        }

        return NotificationCompat.Builder(this, CHANNEL_ID_RECORDING)
            .setContentTitle(getString(R.string.notification_recording_title))
            .setContentText(text)
            .setSmallIcon(R.drawable.ic_notification_mic)
            .setOngoing(true)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .build()
    }

    private fun createNotificationChannels() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return

        val manager = getSystemService(NotificationManager::class.java)
        manager.createNotificationChannel(
            NotificationChannel(
                CHANNEL_ID_RECORDING,
                getString(R.string.notification_channel_recording_name),
                NotificationManager.IMPORTANCE_LOW,
            ).apply {
                description = getString(R.string.notification_channel_recording_description)
            },
        )
    }

    companion object {
        private const val NOTIFICATION_ID = 1001
        const val CHANNEL_ID_RECORDING = "recording_service"

        /** Starts the service if it isn't already running. Never triggers recording by itself. */
        fun ensureRunning(context: Context) {
            val intent = Intent(context, RecordingForegroundService::class.java)
            ContextCompat.startForegroundService(context, intent)
        }
    }
}
