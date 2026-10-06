package com.remoterecorder.agent.util

import android.content.Context
import android.hardware.Sensor
import android.hardware.SensorEvent
import android.hardware.SensorEventListener
import android.hardware.SensorManager
import android.os.Handler
import android.os.Looper
import java.util.concurrent.CountDownLatch
import java.util.concurrent.TimeUnit

/**
 * Reads the ambient light sensor (TYPE_LIGHT) once, in lux. Used to decide
 * whether the room is dark before showing an alert.
 *
 * Not every device has a light sensor (some budget phones omit it), so
 * [readLuxBlocking] returns null when there is no sensor or no reading
 * arrives in time — callers decide what the absence of data means.
 *
 * The sensor sits near the front camera/earpiece, so a covered sensor (phone
 * face-down, hand/case over it) reads dark even in a lit room; this is an
 * approximation, not a calibrated measurement.
 */
class LightSensorReader(context: Context) {

    private val sensorManager = context.getSystemService(Context.SENSOR_SERVICE) as? SensorManager
    private val lightSensor: Sensor? = sensorManager?.getDefaultSensor(Sensor.TYPE_LIGHT)

    fun hasSensor(): Boolean = lightSensor != null

    /**
     * Blocks up to [timeoutMs] for the first lux reading. Returns the lux
     * value, or null if there is no sensor or none arrived in time. Must not
     * be called on the main thread (it blocks).
     */
    fun readLuxBlocking(timeoutMs: Long = 1_500): Float? {
        val manager = sensorManager ?: return null
        val sensor = lightSensor ?: return null

        val latch = CountDownLatch(1)
        var result: Float? = null

        val listener = object : SensorEventListener {
            override fun onSensorChanged(event: SensorEvent) {
                if (result == null && event.values.isNotEmpty()) {
                    result = event.values[0]
                    latch.countDown()
                }
            }

            override fun onAccuracyChanged(sensor: Sensor?, accuracy: Int) {}
        }

        // Register on the main looper so callbacks are delivered even though
        // this method is blocking a background thread.
        manager.registerListener(listener, sensor, SensorManager.SENSOR_DELAY_FASTEST, Handler(Looper.getMainLooper()))
        try {
            latch.await(timeoutMs, TimeUnit.MILLISECONDS)
        } finally {
            manager.unregisterListener(listener)
        }

        return result
    }
}
