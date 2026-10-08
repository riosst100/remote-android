package com.remoterecorder.agent.recording

import android.content.Context
import android.hardware.Sensor
import android.hardware.SensorEvent
import android.hardware.SensorEventListener
import android.hardware.SensorManager
import android.os.Handler
import android.os.Looper
import android.os.PowerManager
import com.remoterecorder.agent.network.ApiClient
import com.remoterecorder.agent.util.AgentLog
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlin.math.abs
import kotlin.math.sqrt

/**
 * Watches the ambient light, accelerometer and proximity sensors while the
 * service is alive and streams a coarse snapshot to the server whenever a
 * reading changes meaningfully. The server broadcasts each snapshot to the
 * dashboard; nothing is persisted.
 *
 * Reporting is deliberately event-driven and throttled rather than a fixed
 * tick: sensors fire far too often to forward every sample, so we only POST
 * when the motion state flips, proximity flips, or lux moves past a relative
 * threshold — and never more than once per [minReportIntervalMs]. This keeps
 * battery and network cost close to the existing 45s heartbeat in the steady
 * (still, unchanging) case while still feeling live when the phone is handled.
 */
class SensorTelemetryMonitor(
    context: Context,
    private val apiClient: ApiClient,
    private val scope: CoroutineScope,
) {
    enum class Motion { STILL, PICKED_UP, PUT_DOWN, MOVING }

    private val sensorManager = context.getSystemService(Context.SENSOR_SERVICE) as? SensorManager
    private val lightSensor = sensorManager?.getDefaultSensor(Sensor.TYPE_LIGHT)
    private val accelSensor = sensorManager?.getDefaultSensor(Sensor.TYPE_ACCELEROMETER)
    private val proximitySensor = sensorManager?.getDefaultSensor(Sensor.TYPE_PROXIMITY)

    private val powerManager = context.getSystemService(Context.POWER_SERVICE) as? PowerManager
    private var wakeLock: PowerManager.WakeLock? = null
    private var tickerJob: Job? = null

    private val handler = Handler(Looper.getMainLooper())

    // Latest raw readings (null until the first sample from each sensor).
    @Volatile private var lux: Float? = null
    @Volatile private var accelMagnitude: Float? = null
    @Volatile private var proximityNear: Boolean? = null

    // Derived + last-reported state, for change detection.
    private var motion: Motion = Motion.STILL
    private var stillSince: Long = System.currentTimeMillis()
    private var lastMovingAt: Long = 0

    private var lastReportedLux: Float? = null
    private var lastReportedMotion: Motion? = null
    private var lastReportedProximity: Boolean? = null
    private var lastReportAt: Long = 0

    private val listener = object : SensorEventListener {
        override fun onSensorChanged(event: SensorEvent) {
            when (event.sensor.type) {
                Sensor.TYPE_LIGHT -> {
                    lux = event.values.firstOrNull()
                    maybeReport()
                }
                Sensor.TYPE_PROXIMITY -> {
                    // By convention values[0] < sensor.maximumRange means "near".
                    val near = event.values.firstOrNull()?.let { it < (proximitySensor?.maximumRange ?: Float.MAX_VALUE) }
                    proximityNear = near
                    maybeReport()
                }
                Sensor.TYPE_ACCELEROMETER -> onAccel(event.values)
            }
        }

        override fun onAccuracyChanged(sensor: Sensor?, accuracy: Int) {}
    }

    @Suppress("WakelockTimeout") // Held for the whole monitoring lifetime by design; released in stop().
    fun start() {
        val manager = sensorManager ?: run {
            AgentLog.w("sensors", "No SensorManager; telemetry disabled.")
            return
        }
        lightSensor?.let { manager.registerListener(listener, it, SensorManager.SENSOR_DELAY_NORMAL, handler) }
        proximitySensor?.let { manager.registerListener(listener, it, SensorManager.SENSOR_DELAY_NORMAL, handler) }
        accelSensor?.let { manager.registerListener(listener, it, SensorManager.SENSOR_DELAY_UI, handler) }

        // Keep the CPU awake while monitoring. The ambient-light and other
        // non-wake-up sensors stop delivering — and coroutine timers stop
        // firing — once the AP suspends with the screen off/Doze; a partial
        // wake lock holds the CPU so both the periodic report below and the
        // sensor callbacks keep working with the screen off. (Battery cost is
        // deliberate: the device is meant to keep reporting while dark/idle.)
        if (wakeLock == null) {
            wakeLock = powerManager?.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, WAKE_LOCK_TAG)?.apply {
                setReferenceCounted(false)
                runCatching { acquire() }
            }
        }

        // Periodic snapshot so the server keeps getting every sensor even when
        // nothing changes — the light sensor is silent on a still phone, so
        // without this a "dark for N seconds" rule would never see the dark.
        tickerJob?.cancel()
        tickerJob = scope.launch {
            while (isActive) {
                delay(REPORT_INTERVAL_MS)
                handler.post { sendReport() }
            }
        }

        AgentLog.i(
            "sensors",
            "Telemetry started (light=${lightSensor != null}, accel=${accelSensor != null}, proximity=${proximitySensor != null}).",
        )
    }

    fun stop() {
        tickerJob?.cancel()
        tickerJob = null
        sensorManager?.unregisterListener(listener)
        wakeLock?.let { if (it.isHeld) runCatching { it.release() } }
        wakeLock = null
    }

    private fun onAccel(values: FloatArray) {
        if (values.size < 3) return
        val magnitude = sqrt(values[0] * values[0] + values[1] * values[1] + values[2] * values[2])
        accelMagnitude = magnitude

        // Deviation from gravity (~9.81). Small = at rest, large = being moved.
        val moving = abs(magnitude - SensorManager.GRAVITY_EARTH) > MOVING_THRESHOLD
        val now = System.currentTimeMillis()

        val newMotion = when {
            moving -> {
                val wasStill = motion == Motion.STILL
                lastMovingAt = now
                if (wasStill) Motion.PICKED_UP else Motion.MOVING
            }
            // Not moving right now.
            now - lastMovingAt < SETTLE_MS -> Motion.PUT_DOWN // just stopped — still settling
            else -> Motion.STILL
        }

        if (newMotion != motion) {
            motion = newMotion
            if (newMotion == Motion.STILL) stillSince = now
            maybeReport()
        }
    }

    /**
     * Sends a snapshot if something changed enough and we're outside the
     * throttle window. Runs on the sensor callback thread (main looper); the
     * actual POST is dispatched to IO.
     */
    private fun maybeReport() {
        val now = System.currentTimeMillis()
        if (now - lastReportAt < minReportIntervalMs) return

        val luxChanged = luxChangedEnough(lastReportedLux, lux)
        val motionChanged = motion != lastReportedMotion
        val proximityChanged = proximityNear != lastReportedProximity
        val actuatorChanged = ActuatorState.changedSinceReport
        if (!luxChanged && !motionChanged && !proximityChanged && !actuatorChanged) return

        sendReport()
    }

    /** Snapshots current state, updates the change-tracking baseline, and POSTs. */
    private fun sendReport() {
        lastReportAt = System.currentTimeMillis()
        lastReportedLux = lux
        lastReportedMotion = motion
        lastReportedProximity = proximityNear
        ActuatorState.clearChangedFlag()

        val snapLux = lux
        val snapMotion = motion.name
        val snapMag = accelMagnitude
        val snapProximity = proximityNear
        val snapPopup = ActuatorState.isPopupShown()
        val snapFlash = ActuatorState.isFlashOn()
        val snapVideo = ActuatorState.isVideoRecording()

        scope.launch(Dispatchers.IO) {
            runCatching {
                apiClient.reportSensors(snapLux, snapMotion, snapMag, snapProximity, snapPopup, snapFlash, snapVideo)
            }.onFailure { AgentLog.w("sensors", "Sensor report failed", it) }
        }
    }

    /**
     * Forces an immediate report regardless of throttle or change detection,
     * used right after an actuator (flash/popup) changes so the dashboard
     * reflects it promptly even if the sensor values themselves are static.
     */
    fun reportNow() {
        sendReport()
    }

    private fun luxChangedEnough(old: Float?, new: Float?): Boolean {
        if (new == null) return false
        if (old == null) return true
        // Relative threshold so we're sensitive in the dark and not twitchy in daylight.
        val delta = abs(new - old)
        return delta > 5f && delta > old * 0.2f
    }

    private val minReportIntervalMs = 2_000L

    private companion object {
        const val MOVING_THRESHOLD = 1.2f // m/s^2 away from gravity to count as motion
        const val SETTLE_MS = 1_500L // window after last motion still reported as PUT_DOWN
        const val REPORT_INTERVAL_MS = 3_000L // forced full-sensor snapshot cadence (screen on or off)
        const val WAKE_LOCK_TAG = "RemoteRecorder:sensors"
    }
}
