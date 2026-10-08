package com.remoterecorder.agent.recording

import android.content.Context
import android.hardware.camera2.CameraCharacteristics
import android.hardware.camera2.CameraManager
import android.os.Handler
import android.os.Looper
import com.remoterecorder.agent.util.AgentLog

/**
 * Toggles the device's camera flashlight (torch) via Camera2's
 * [CameraManager.setTorchMode]. Torch mode needs no CAMERA permission on
 * API 23+ — it's a system-mediated toggle, not camera capture — which is
 * why the agent can drive it without prompting the user for anything.
 *
 * Finds the first camera that reports a flash unit (usually the back
 * camera); throws if the device has no flash, so the caller can report a
 * flash_error ack back to the dashboard rather than silently no-op.
 */
class TorchController(context: Context) {

    private val cameraManager = context.getSystemService(Context.CAMERA_SERVICE) as CameraManager
    private var torchCallback: CameraManager.TorchCallback? = null

    fun setEnabled(enabled: Boolean) {
        val cameraId = findFlashCameraId()
            ?: throw IllegalStateException("No camera with a flash unit is available on this device.")

        cameraManager.setTorchMode(cameraId, enabled)
        AgentLog.i("torch", "Torch set to $enabled on camera $cameraId.")
    }

    /**
     * Observes the real torch state of the flash camera, so changes made
     * outside the agent (the Quick Settings tile, another app, the system
     * turning it off when a camera opens) are reported too — not just the
     * ones the agent itself applied. Android invokes the callback once
     * right after registration with the current state, which also syncs
     * the initial value. An unavailable torch (camera in use) is reported
     * as off, since the flashlight can't be lit in that state.
     */
    fun startWatching(onChanged: (Boolean) -> Unit) {
        if (torchCallback != null) return
        val flashCameraId = runCatching { findFlashCameraId() }.getOrNull() ?: return

        val callback = object : CameraManager.TorchCallback() {
            override fun onTorchModeChanged(cameraId: String, enabled: Boolean) {
                if (cameraId == flashCameraId) onChanged(enabled)
            }

            override fun onTorchModeUnavailable(cameraId: String) {
                if (cameraId == flashCameraId) onChanged(false)
            }
        }
        cameraManager.registerTorchCallback(callback, Handler(Looper.getMainLooper()))
        torchCallback = callback
    }

    fun stopWatching() {
        torchCallback?.let { cameraManager.unregisterTorchCallback(it) }
        torchCallback = null
    }

    private fun findFlashCameraId(): String? {
        return cameraManager.cameraIdList.firstOrNull { id ->
            val hasFlash = cameraManager.getCameraCharacteristics(id)
                .get(CameraCharacteristics.FLASH_INFO_AVAILABLE)
            hasFlash == true
        }
    }
}
