package com.remoterecorder.agent.ui

import android.app.KeyguardManager
import android.content.Context
import android.content.Intent
import android.hardware.Sensor
import android.hardware.SensorEvent
import android.hardware.SensorEventListener
import android.hardware.SensorManager
import android.media.AudioAttributes
import android.media.AudioManager
import android.media.Ringtone
import android.media.RingtoneManager
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.os.VibrationEffect
import android.os.Vibrator
import android.os.VibratorManager
import android.view.WindowManager
import android.widget.Button
import android.widget.TextView
import androidx.activity.OnBackPressedCallback
import androidx.appcompat.app.AppCompatActivity
import com.remoterecorder.agent.R
import com.remoterecorder.agent.util.AgentLog

/**
 * Full-screen alert shown in response to a SHOW_ALERT command. Launched by
 * a full-screen-intent notification (see RecordingForegroundService), so it
 * appears over the lock screen like an incoming call/alarm. Plays the
 * device's alarm ringtone and vibrates until the user taps Dismiss (or
 * swipes the activity away). No auto-timeout — it is meant to demand
 * attention.
 */
class AlertActivity : AppCompatActivity() {

    private var ringtone: Ringtone? = null
    private var vibrator: Vibrator? = null
    private var audioManager: AudioManager? = null
    private var previousAlarmVolume: Int? = null
    private val mainHandler = Handler(Looper.getMainLooper())

    private var sensorManager: SensorManager? = null
    private var lightListener: SensorEventListener? = null

    private var pendingTitle: String = "Attention"
    private var pendingMessage: String = ""
    private var pendingVolume: Int = 100
    private var pendingBrightness: Int = 100
    private var pendingButtonLabel: String = "Dismiss"

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        visibleInstance = this
        com.remoterecorder.agent.recording.ActuatorState.setPopupShown(true)

        showWhenLockedAndTurnScreenOn()
        setContentView(R.layout.activity_alert)

        val title = intent.getStringExtra(EXTRA_TITLE)?.takeIf { it.isNotBlank() } ?: "Attention"
        val message = intent.getStringExtra(EXTRA_MESSAGE).orEmpty()
        val volumePercent = intent.getIntExtra(EXTRA_VOLUME, 100).coerceIn(0, 100)
        val brightnessPercent = intent.getIntExtra(EXTRA_BRIGHTNESS, 100).coerceIn(0, 100)
        val buttonLabel = intent.getStringExtra(EXTRA_BUTTON_LABEL)?.takeIf { it.isNotBlank() } ?: "Dismiss"
        applyScreenBrightness(brightnessPercent)

        findViewById<TextView>(R.id.alert_title).text = title
        findViewById<TextView>(R.id.alert_message).text = message
        findViewById<Button>(R.id.alert_dismiss).apply {
            text = buttonLabel
            // Force the Xiaomi-orange tint from code too — some OEM themes
            // (MIUI/One UI) otherwise re-tint buttons with the system accent.
            backgroundTintList = android.content.res.ColorStateList.valueOf(0xFFFF6900.toInt())
            setOnClickListener {
                // The alert no longer controls the flashlight, so dismissing
                // just closes — it leaves the torch in whatever state it was.
                finish()
            }
        }

        // Block the hardware/gesture Back button: the alert must only be
        // dismissible via the on-screen Dismiss button, so it can't be
        // swiped away by accident or to bypass it. (enabled = always
        // consume Back and do nothing.)
        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() { /* intentionally ignored */ }
        })

        startAlarm(volumePercent)

        // Remember what to re-show if the user leaves via Home/Recents (see
        // onStop) — this is how the alert "forces itself back" without the
        // screen-pinning toast.
        pendingTitle = title
        pendingMessage = message
        pendingVolume = volumePercent
        pendingBrightness = brightnessPercent
        pendingButtonLabel = buttonLabel

        // Only auto-close on brightening when explicitly asked (the old
        // dark-room alarm). Manual alerts stay up until Dismiss.
        if (intent.getBooleanExtra(EXTRA_LIGHT_AUTO_CLOSE, false)) {
            startLightAutoClose()
        }
    }

    /**
     * Watches the ambient light sensor while the alert is shown and
     * auto-closes it once the room becomes bright (lights turned on) — the
     * mirror of the "only show when dark" rule. A small hysteresis above the
     * show-threshold avoids flapping around the boundary. No sensor → nothing
     * to watch, the alert just stays until Dismiss.
     */
    private fun startLightAutoClose() {
        val sm = (getSystemService(Context.SENSOR_SERVICE) as? SensorManager) ?: return
        val sensor = sm.getDefaultSensor(Sensor.TYPE_LIGHT) ?: return
        sensorManager = sm

        lightListener = object : SensorEventListener {
            override fun onSensorChanged(event: SensorEvent) {
                val lux = event.values.firstOrNull() ?: return
                if (lux >= LIGHT_AUTO_CLOSE_LUX && !isFinishing) {
                    AgentLog.i("alert", "Room became bright (lux=$lux); auto-closing alert.")
                    finish()
                }
            }

            override fun onAccuracyChanged(sensor: Sensor?, accuracy: Int) {}
        }
        sm.registerListener(lightListener, sensor, SensorManager.SENSOR_DELAY_NORMAL)
    }

    private fun showWhenLockedAndTurnScreenOn() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O_MR1) {
            setShowWhenLocked(true)
            setTurnScreenOn(true)
            (getSystemService(Context.KEYGUARD_SERVICE) as? KeyguardManager)?.requestDismissKeyguard(this, null)
        } else {
            @Suppress("DEPRECATION")
            window.addFlags(
                WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED
                    or WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON
                    or WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON,
            )
        }
    }

    /**
     * Sets the alarm stream to [volumePercent] of its max so the alert is
     * heard even if the phone is on silent/vibrate — the ringer mode
     * doesn't mute STREAM_ALARM, but the user may have turned the alarm
     * volume down. The percent comes from the dashboard (defaults to 100).
     * The previous level is saved and restored on dismiss so we don't
     * permanently change the user's setting.
     */
    private fun raiseAlarmVolume(volumePercent: Int) {
        val am = (getSystemService(Context.AUDIO_SERVICE) as? AudioManager) ?: return
        audioManager = am
        runCatching {
            previousAlarmVolume = am.getStreamVolume(AudioManager.STREAM_ALARM)
            val max = am.getStreamMaxVolume(AudioManager.STREAM_ALARM)
            val target = Math.round(max * volumePercent / 100f).coerceIn(0, max)
            am.setStreamVolume(AudioManager.STREAM_ALARM, target, 0)
        }.onFailure { AgentLog.w("alert", "Could not set alarm volume", it) }
    }

    private fun restoreAlarmVolume() {
        val am = audioManager ?: return
        val previous = previousAlarmVolume ?: return
        runCatching { am.setStreamVolume(AudioManager.STREAM_ALARM, previous, 0) }
        previousAlarmVolume = null
    }

    /**
     * Sets this window's brightness to [brightnessPercent] of full. This is
     * a per-window override (WindowManager.LayoutParams.screenBrightness),
     * so it needs no permission and is reset automatically by the system
     * when the activity goes away — nothing to restore manually, unlike the
     * alarm volume. A minimum floor keeps the screen from going fully black
     * even at 0%.
     */
    private fun applyScreenBrightness(brightnessPercent: Int) {
        val layoutParams = window.attributes
        layoutParams.screenBrightness = (brightnessPercent / 100f).coerceIn(0.05f, 1.0f)
        window.attributes = layoutParams
    }

    private fun startAlarm(volumePercent: Int) {
        raiseAlarmVolume(volumePercent)
        runCatching {
            val uri = RingtoneManager.getActualDefaultRingtoneUri(this, RingtoneManager.TYPE_ALARM)
                ?: RingtoneManager.getDefaultUri(RingtoneManager.TYPE_NOTIFICATION)
            ringtone = RingtoneManager.getRingtone(this, uri)?.apply {
                audioAttributes = AudioAttributes.Builder()
                    .setUsage(AudioAttributes.USAGE_ALARM)
                    .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                    .build()
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) isLooping = true
                play()
            }
        }.onFailure { AgentLog.e("alert", "Failed to play alarm sound", it) }

        startVibration()
    }

    private fun startVibration() {
        vibrator = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            (getSystemService(Context.VIBRATOR_MANAGER_SERVICE) as? VibratorManager)?.defaultVibrator
        } else {
            @Suppress("DEPRECATION")
            getSystemService(Context.VIBRATOR_SERVICE) as? Vibrator
        }

        val pattern = longArrayOf(0, 600, 400)
        runCatching {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                vibrator?.vibrate(VibrationEffect.createWaveform(pattern, 0))
            } else {
                @Suppress("DEPRECATION")
                vibrator?.vibrate(pattern, 0)
            }
        }
    }

    private fun stopAlarm() {
        runCatching { ringtone?.stop() }
        ringtone = null
        runCatching { vibrator?.cancel() }
        vibrator = null
        restoreAlarmVolume()
    }

    /**
     * If the user leaves the alert via Home or Recent-apps (so the activity
     * stops but isn't finishing — Dismiss calls finish(), which sets
     * isFinishing=true and skips this), immediately bring it back to the
     * front. The alert can't be blocked at the OS level without Device Owner
     * / screen pinning (which shows a toast), so instead it re-asserts
     * itself until the user actually taps Dismiss. A short delay lets the
     * launcher settle first so the re-launch reliably wins the foreground.
     */
    override fun onStop() {
        super.onStop()
        if (!isFinishing) {
            mainHandler.postDelayed({
                if (!isFinishing) {
                    startActivity(
                        Intent(this, AlertActivity::class.java).apply {
                            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_REORDER_TO_FRONT or Intent.FLAG_ACTIVITY_SINGLE_TOP)
                            putExtra(EXTRA_TITLE, pendingTitle)
                            putExtra(EXTRA_MESSAGE, pendingMessage)
                            putExtra(EXTRA_VOLUME, pendingVolume)
                            putExtra(EXTRA_BRIGHTNESS, pendingBrightness)
                            putExtra(EXTRA_BUTTON_LABEL, pendingButtonLabel)
                        },
                    )
                }
            }, 300)
        }
    }

    override fun onDestroy() {
        if (visibleInstance === this) visibleInstance = null
        com.remoterecorder.agent.recording.ActuatorState.setPopupShown(false)
        mainHandler.removeCallbacksAndMessages(null)
        lightListener?.let { sensorManager?.unregisterListener(it) }
        lightListener = null
        stopAlarm()
        super.onDestroy()
    }

    companion object {
        /** The currently-showing alert, if any, so a dashboard DISMISS_ALERT can close it. */
        @Volatile
        private var visibleInstance: AlertActivity? = null

        /** Closes the visible alert (if any) from the service. Safe to call off the main thread. */
        fun dismissVisible() {
            visibleInstance?.let { activity ->
                activity.runOnUiThread { runCatching { activity.finish() } }
            }
        }

        const val EXTRA_TITLE = "alert_title"
        const val EXTRA_MESSAGE = "alert_message"
        const val EXTRA_VOLUME = "alert_volume"
        const val EXTRA_BRIGHTNESS = "alert_brightness"
        const val EXTRA_BUTTON_LABEL = "alert_button_label"
        const val EXTRA_LIGHT_AUTO_CLOSE = "alert_light_auto_close"

        /**
         * Lux at or above which the alert auto-closes (lights turned on).
         * Set a bit above the 10-lux "dark" show-threshold so turning a light
         * on clearly crosses it and it doesn't flap at the boundary.
         */
        private const val LIGHT_AUTO_CLOSE_LUX = 20f
    }
}
