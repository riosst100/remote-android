package com.remoterecorder.agent.recording

import java.util.concurrent.atomic.AtomicBoolean

/**
 * Process-wide live state of the two things the dashboard controls and
 * monitors: whether an alert popup is currently showing, and whether the
 * flashlight is on. Updated by whoever changes the state (the flash command
 * handler, AlertActivity's lifecycle) and read by SensorTelemetryMonitor to
 * include in its telemetry so the dashboard can reflect it live.
 *
 * A plain singleton rather than DI because AlertActivity and the service run
 * in the same process but have no shared owner to pass this through.
 */
object ActuatorState {
    private val flashOn = AtomicBoolean(false)
    private val popupShown = AtomicBoolean(false)
    private val videoRecording = AtomicBoolean(false)

    /** Set when a change should be reported promptly, cleared once reported. */
    @Volatile
    var changedSinceReport: Boolean = false
        private set

    fun setFlashOn(on: Boolean) {
        if (flashOn.getAndSet(on) != on) changedSinceReport = true
    }

    fun setPopupShown(shown: Boolean) {
        if (popupShown.getAndSet(shown) != shown) changedSinceReport = true
    }

    fun setVideoRecording(on: Boolean) {
        if (videoRecording.getAndSet(on) != on) changedSinceReport = true
    }

    fun isFlashOn(): Boolean = flashOn.get()

    fun isPopupShown(): Boolean = popupShown.get()

    fun isVideoRecording(): Boolean = videoRecording.get()

    fun clearChangedFlag() {
        changedSinceReport = false
    }
}
