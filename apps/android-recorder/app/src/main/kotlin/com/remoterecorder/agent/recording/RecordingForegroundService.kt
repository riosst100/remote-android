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
import android.provider.Settings
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat
import com.remoterecorder.agent.R
import com.remoterecorder.agent.model.Command
import com.remoterecorder.agent.model.DeviceSchedule
import com.remoterecorder.agent.network.ApiClient
import com.remoterecorder.agent.network.ConnectionStatus
import com.remoterecorder.agent.network.ReverbSocketClient
import com.remoterecorder.agent.util.AgentLog
import com.remoterecorder.agent.util.DeviceCredentialStore
import com.remoterecorder.agent.util.PendingRecordingStore
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import org.json.JSONObject

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
    private lateinit var videoSessionManager: VideoSessionManager
    private lateinit var torchController: TorchController
    private lateinit var credentials: DeviceCredentialStore
    private lateinit var sensorMonitor: SensorTelemetryMonitor
    private val serviceScope = CoroutineScope(Dispatchers.IO + Job())
    private var heartbeatJob: Job? = null
    private var sensorsStarted = false

    override fun onCreate() {
        super.onCreate()
        credentials = DeviceCredentialStore(this)
        apiClient = ApiClient { credentials.token }
        sessionManager = RecordingSessionManager(this, apiClient)
        videoSessionManager = VideoSessionManager(this, apiClient)
        torchController = TorchController(this)
        sensorMonitor = SensorTelemetryMonitor(this, apiClient, serviceScope)

        socketClient = ReverbSocketClient(
            tokenProvider = { credentials.token },
            onCommandEvent = ::onCommandEvent,
            onStatusChanged = ::onConnectionStatusChanged,
        )

        createNotificationChannels()
        resumeUnfinishedScheduleRecording()
    }

    /**
     * Detects a schedule-triggered recording that was still capturing (per
     * PendingRecordingStore) when the process was last killed —
     * sessionManager itself has no memory of it (it's a fresh in-memory
     * object at this point, same as `state == IDLE`).
     *
     * Unlike the old chunk-per-20s design, there's nothing to resume:
     * MediaRecorder doesn't survive a process death, and the whole
     * session now lives in one file that was still only partially written
     * when the kill happened — none of it was uploaded yet, so none of it
     * is salvageable. This just reports the loss so the recording isn't
     * left dangling in RECORDING state forever. Only ever finds at most
     * one entry in practice (a device runs one recording at a time), but
     * loops defensively in case of prior inconsistent state.
     */
    private fun resumeUnfinishedScheduleRecording() {
        val store = PendingRecordingStore(this)

        for (pending in store.getUnfinished()) {
            sessionManager.reportUnrecoverableAfterRestart(pending)
        }
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        startForegroundWithNotification(recording = false)

        when (intent?.action) {
            ACTION_SCHEDULE_START -> handleScheduleStart(intent)
            ACTION_SCHEDULE_STOP -> handleScheduleStop(intent)
        }

        val serverDeviceId = credentials.serverDeviceId
        if (serverDeviceId <= 0) {
            AgentLog.e("service", "Device is not registered yet (no server device id); cannot subscribe for commands.")
            return START_STICKY
        }

        socketClient.connect("private-devices.$serverDeviceId")
        startHeartbeatLoop()

        // Idempotent: onStartCommand fires repeatedly, but telemetry should
        // start once and keep running for the life of the service.
        if (!sensorsStarted) {
            sensorMonitor.start()
            sensorsStarted = true
        }

        return START_STICKY
    }

    /**
     * Handles an alarm-fired (or fallback-poll-fired) schedule start.
     * Called from `onStartCommand`, which runs with a short time budget
     * when invoked via `ScheduleAlarmReceiver` — this hands off to the
     * already-cheap `RecordingSessionManager.startFromSchedule` rather than
     * doing any blocking network work itself.
     */
    private fun handleScheduleStart(intent: Intent) {
        val scheduleJson = intent.getStringExtra(EXTRA_SCHEDULE_JSON)
        val schedule = scheduleJson?.let { runCatching { DeviceSchedule.fromJson(JSONObject(it)) }.getOrNull() }
        if (schedule == null) {
            AgentLog.e("service", "ACTION_SCHEDULE_START with missing/malformed schedule payload; ignoring.")
            return
        }

        val recordingId = sessionManager.startFromSchedule(schedule)
        if (recordingId != null) {
            val stopAt = System.currentTimeMillis() + schedule.durationMinutes * 60_000L
            ScheduleAlarmScheduler.armStopAlarm(this, recordingId, stopAt)
        }
        updateNotification(recording = sessionManager.isRecording())
    }

    private fun handleScheduleStop(intent: Intent) {
        val recordingId = intent.getStringExtra(EXTRA_RECORDING_ID)
        if (recordingId == null) {
            AgentLog.e("service", "ACTION_SCHEDULE_STOP with no recording id; ignoring.")
            return
        }
        sessionManager.stopFromSchedule(recordingId)
        updateNotification(recording = sessionManager.isRecording())
    }

    override fun onDestroy() {
        heartbeatJob?.cancel()
        socketClient.disconnect()
        sensorMonitor.stop()
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

        if (event == "FlashCommandRequested") {
            val on = data.optString("command") == "FLASH_ON"
            val command = runCatching { Command.flashFromJson(data, on) }.getOrNull() ?: return
            handleFlashCommand(command, on)
            return
        }

        if (event == "AlertCommandRequested") {
            handleAlertCommand(data)
            return
        }

        if (event == "DismissAlertCommandRequested") {
            handleDismissAlertCommand(data)
            return
        }

        // Audio and video start/stop share the same broadcast event names;
        // the `command` field tells them apart (START_VIDEO vs START_RECORDING).
        val commandKind = data.optString("command")

        if (commandKind == "START_VIDEO" || commandKind == "STOP_VIDEO") {
            val videoCommand = when (commandKind) {
                "START_VIDEO" -> runCatching { Command.startVideoFromJson(data) }.getOrNull()
                "STOP_VIDEO" -> runCatching { Command.stopVideoFromJson(data) }.getOrNull()
                else -> null
            } ?: return
            videoSessionManager.handleCommand(videoCommand)
            updateNotification(recording = sessionManager.isRecording() || videoSessionManager.isRecording())
            return
        }

        val command = when (event) {
            "RecordingStartRequested" -> runCatching { Command.startFromJson(data) }.getOrNull()
            "RecordingStopRequested" -> runCatching { Command.stopFromJson(data) }.getOrNull()
            else -> null
        } ?: return

        sessionManager.handleCommand(command)
        updateNotification(recording = sessionManager.isRecording())
    }

    /**
     * Toggles the torch and acks the command. Runs on serviceScope (IO) so
     * both the Camera2 call and the HTTP ack stay off the main thread. A
     * missing flash unit (or any failure) reports flash_error so the
     * dashboard command is marked FAILED rather than left hanging.
     */
    private fun handleFlashCommand(command: Command, on: Boolean) {
        serviceScope.launch {
            val result = runCatching { torchController.setEnabled(on) }
            if (result.isSuccess) {
                ActuatorState.setFlashOn(on)
                sensorMonitor.reportNow()
            }
            val ackEvent = if (result.isSuccess) "flash_applied" else "flash_error"
            val errorMessage = result.exceptionOrNull()?.message

            if (result.isFailure) {
                AgentLog.e("service", "Failed to toggle torch (on=$on)", result.exceptionOrNull())
            }

            runCatching {
                apiClient.acknowledgeCommand(
                    command.commandId,
                    ackEvent,
                    errorCode = if (result.isFailure) "SERVICE_ERROR" else null,
                    errorMessage = errorMessage,
                )
            }.onFailure { AgentLog.e("service", "Failed to ack flash command ${command.commandId}", it) }
        }
    }

    /**
     * Shows a full-screen alert — but only when the room is dark. Reads the
     * ambient light sensor first (off the main thread, since the read
     * blocks); if the room isn't dark the alert is skipped. Either way the
     * command is acked so the dashboard isn't left waiting.
     *
     * The alert itself uses a full-screen-intent notification that launches
     * [com.remoterecorder.agent.ui.AlertActivity], which is what lets it
     * surface over the lock screen and from the background on modern Android.
     */
    private fun handleAlertCommand(data: org.json.JSONObject) {
        val commandId = data.optString("command_id").takeIf { it.isNotBlank() } ?: return
        val title = data.optString("title").takeIf { it.isNotBlank() } ?: "Attention"
        val message = data.optString("message")
        val volume = if (data.has("volume")) data.optInt("volume", 100) else 100
        val brightness = if (data.has("brightness")) data.optInt("brightness", 100) else 100
        val buttonLabel = data.optString("button_label").takeIf { it.isNotBlank() } ?: "Dismiss"

        serviceScope.launch {
            // A manually-sent alert from the dashboard always shows, whatever
            // the lighting. (Sensor-rule-triggered alerts apply their own lux
            // condition before getting here.)
            val result = runCatching { showAlertNotification(title, message, volume, brightness, buttonLabel) }
            if (result.isFailure) {
                AgentLog.e("service", "Failed to show alert", result.exceptionOrNull())
            }

            ackAlert(
                commandId,
                if (result.isSuccess) "alert_shown" else "alert_error",
                if (result.isFailure) "SERVICE_ERROR" else null,
                result.exceptionOrNull()?.message,
            )
        }
    }

    /**
     * Closes a currently-showing alert popup on dashboard request by
     * broadcasting [AlertActivity.ACTION_DISMISS], which the activity listens
     * for and finishes itself. Acks either way so the command resolves.
     */
    private fun handleDismissAlertCommand(data: org.json.JSONObject) {
        val commandId = data.optString("command_id").takeIf { it.isNotBlank() } ?: return
        serviceScope.launch {
            runCatching { com.remoterecorder.agent.ui.AlertActivity.dismissVisible() }
                .onFailure { AgentLog.e("service", "Failed to dismiss alert", it) }
            ackAlert(commandId, "alert_dismissed")
        }
    }

    private fun ackAlert(commandId: String, event: String, errorCode: String? = null, errorMessage: String? = null) {
        runCatching {
            apiClient.acknowledgeCommand(commandId, event, errorCode = errorCode, errorMessage = errorMessage)
        }.onFailure { AgentLog.e("service", "Failed to ack alert command $commandId", it) }
    }

    private fun showAlertNotification(title: String, message: String, volume: Int, brightness: Int, buttonLabel: String) {
        val activityIntent = Intent(this, com.remoterecorder.agent.ui.AlertActivity::class.java).apply {
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP)
            putExtra(com.remoterecorder.agent.ui.AlertActivity.EXTRA_TITLE, title)
            putExtra(com.remoterecorder.agent.ui.AlertActivity.EXTRA_MESSAGE, message)
            putExtra(com.remoterecorder.agent.ui.AlertActivity.EXTRA_VOLUME, volume)
            putExtra(com.remoterecorder.agent.ui.AlertActivity.EXTRA_BRIGHTNESS, brightness)
            putExtra(com.remoterecorder.agent.ui.AlertActivity.EXTRA_BUTTON_LABEL, buttonLabel)
        }
        val pendingIntent = android.app.PendingIntent.getActivity(
            this,
            ALERT_NOTIFICATION_ID,
            activityIntent,
            android.app.PendingIntent.FLAG_UPDATE_CURRENT or android.app.PendingIntent.FLAG_IMMUTABLE,
        )

        // Full-screen-intent notification: the reliable path on a locked or
        // dozing screen, and the required fallback when background-activity
        // starts are blocked (Android 10+). Always posted.
        val notification = NotificationCompat.Builder(this, CHANNEL_ID_ALERT)
            .setContentTitle(title)
            .setContentText(message)
            .setSmallIcon(R.drawable.ic_notification_mic)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setCategory(NotificationCompat.CATEGORY_ALARM)
            .setAutoCancel(true)
            .setFullScreenIntent(pendingIntent, true)
            .build()

        getSystemService(NotificationManager::class.java).notify(ALERT_NOTIFICATION_ID, notification)

        // When the user has granted "Display over other apps"
        // (SYSTEM_ALERT_WINDOW), a background activity start is allowed, so
        // we can force the alert to the front even over another running
        // app. Without that permission this would be silently dropped on
        // modern Android, so the full-screen-intent notification above is
        // what covers that case.
        if (Settings.canDrawOverlays(this)) {
            runCatching { startActivity(activityIntent) }
                .onFailure { AgentLog.w("service", "Could not foreground alert activity directly", it) }
        } else {
            AgentLog.w("service", "Overlay permission not granted; alert shown via full-screen notification only.")
        }
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

        // High-importance channel for the full-screen alert. IMPORTANCE_HIGH
        // is required for a full-screen intent to actually surface.
        manager.createNotificationChannel(
            NotificationChannel(
                CHANNEL_ID_ALERT,
                "Device Alerts",
                NotificationManager.IMPORTANCE_HIGH,
            ).apply {
                description = "Remote full-screen alerts shown on this device"
            },
        )
    }

    companion object {
        /**
         * Below this many lux the room counts as "dark" and the alert is
         * shown. ~10 lux is roughly a dim/unlit room at night; normal indoor
         * lighting is 100+ lux.
         */
        private const val DARK_ROOM_LUX_THRESHOLD = 10f

        private const val NOTIFICATION_ID = 1001
        private const val ALERT_NOTIFICATION_ID = 1002
        const val CHANNEL_ID_RECORDING = "recording_service"
        const val CHANNEL_ID_ALERT = "device_alert"

        const val ACTION_SCHEDULE_START = "com.remoterecorder.agent.action.SCHEDULE_START"
        const val ACTION_SCHEDULE_STOP = "com.remoterecorder.agent.action.SCHEDULE_STOP"
        private const val EXTRA_SCHEDULE_JSON = "schedule_json"
        private const val EXTRA_RECORDING_ID = "recording_id"

        /** Starts the service if it isn't already running. Never triggers recording by itself. */
        fun ensureRunning(context: Context) {
            val intent = Intent(context, RecordingForegroundService::class.java)
            ContextCompat.startForegroundService(context, intent)
        }

        /** Entry point for a fired schedule-start alarm (or fallback poll). */
        fun startFromSchedule(context: Context, schedule: DeviceSchedule) {
            val intent = Intent(context, RecordingForegroundService::class.java)
                .setAction(ACTION_SCHEDULE_START)
                .putExtra(EXTRA_SCHEDULE_JSON, schedule.toJson().toString())
            ContextCompat.startForegroundService(context, intent)
        }

        /** Entry point for a fired schedule-stop alarm. */
        fun stopFromSchedule(context: Context, recordingId: String) {
            val intent = Intent(context, RecordingForegroundService::class.java)
                .setAction(ACTION_SCHEDULE_STOP)
                .putExtra(EXTRA_RECORDING_ID, recordingId)
            ContextCompat.startForegroundService(context, intent)
        }
    }
}
