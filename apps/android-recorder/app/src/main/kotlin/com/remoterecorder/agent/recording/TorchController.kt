package com.remoterecorder.agent.recording

import android.content.Context
import android.hardware.camera2.CameraCharacteristics
import android.hardware.camera2.CameraManager
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

    fun setEnabled(enabled: Boolean) {
        val cameraId = findFlashCameraId()
            ?: throw IllegalStateException("No camera with a flash unit is available on this device.")

        cameraManager.setTorchMode(cameraId, enabled)
        AgentLog.i("torch", "Torch set to $enabled on camera $cameraId.")
    }

    private fun findFlashCameraId(): String? {
        return cameraManager.cameraIdList.firstOrNull { id ->
            val hasFlash = cameraManager.getCameraCharacteristics(id)
                .get(CameraCharacteristics.FLASH_INFO_AVAILABLE)
            hasFlash == true
        }
    }
}
