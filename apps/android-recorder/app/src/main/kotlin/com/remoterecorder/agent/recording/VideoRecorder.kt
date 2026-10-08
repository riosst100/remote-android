package com.remoterecorder.agent.recording

import android.Manifest
import android.annotation.SuppressLint
import android.content.Context
import android.content.pm.PackageManager
import androidx.camera.core.CameraSelector
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.camera.video.FileOutputOptions
import androidx.camera.video.Quality
import androidx.camera.video.QualitySelector
import androidx.camera.video.Recorder
import androidx.camera.video.Recording
import androidx.camera.video.VideoCapture
import androidx.camera.video.VideoRecordEvent
import androidx.core.content.ContextCompat
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleOwner
import androidx.lifecycle.LifecycleRegistry
import com.remoterecorder.agent.util.AgentLog
import java.io.File
import java.util.concurrent.CountDownLatch
import java.util.concurrent.TimeUnit

/**
 * Records video from the rear camera with no preview, via CameraX. Output
 * goes to a single MP4 file in the app's internal cache (not the gallery,
 * not visible to the media scanner), which the session manager later uploads
 * and then deletes.
 *
 * CameraX needs a LifecycleOwner to bind to; since this runs inside a Service
 * (which isn't one by default), we host a tiny self-driven [LifecycleRegistry]
 * that we move to RESUMED while recording and DESTROYED when done.
 *
 * All CameraX calls must happen on the main thread, so binding is marshalled
 * there and the caller (on an IO coroutine) blocks on a latch until the
 * recording has actually started or failed.
 */
class VideoRecorder(
    private val context: Context,
    private val onError: (Throwable) -> Unit,
) : LifecycleOwner {

    private val lifecycleRegistry = LifecycleRegistry(this)
    override val lifecycle: Lifecycle get() = lifecycleRegistry

    private var cameraProvider: ProcessCameraProvider? = null
    private var activeRecording: Recording? = null
    private var outputFile: File? = null

    private val mainExecutor = ContextCompat.getMainExecutor(context)

    fun hasCameraPermission(): Boolean =
        ContextCompat.checkSelfPermission(context, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED

    /**
     * Starts recording. Blocks (up to [startTimeoutMs]) until capture has
     * begun or failed. Returns the output file on success, null on failure.
     * Must not be called on the main thread.
     */
    @SuppressLint("MissingPermission")
    fun start(startTimeoutMs: Long = 10_000): File? {
        if (!hasCameraPermission()) {
            onError(SecurityException("CAMERA permission not granted."))
            return null
        }

        val file = File(context.cacheDir, "video_session.mp4").apply { if (exists()) delete() }
        outputFile = file

        val startLatch = CountDownLatch(1)
        var started = false

        mainExecutor.execute {
            lifecycleRegistry.currentState = Lifecycle.State.RESUMED

            val future = ProcessCameraProvider.getInstance(context)
            future.addListener({
                try {
                    val provider = future.get()
                    cameraProvider = provider

                    val recorder = Recorder.Builder()
                        .setQualitySelector(
                            QualitySelector.from(
                                Quality.FHD, // 1080p
                                androidx.camera.video.FallbackStrategy.lowerQualityOrHigherThan(Quality.HD),
                            ),
                        )
                        .build()
                    val videoCapture = VideoCapture.withOutput(recorder)

                    provider.unbindAll()
                    provider.bindToLifecycle(this, CameraSelector.DEFAULT_BACK_CAMERA, videoCapture)

                    val outputOptions = FileOutputOptions.Builder(file).build()
                    activeRecording = videoCapture.output
                        .prepareRecording(context, outputOptions)
                        .start(mainExecutor) { event ->
                            when (event) {
                                is VideoRecordEvent.Start -> {
                                    AgentLog.i("video", "Recording started -> ${file.name}")
                                    started = true
                                    startLatch.countDown()
                                }
                                is VideoRecordEvent.Finalize -> {
                                    if (event.hasError()) {
                                        AgentLog.e("video", "Finalize error code=${event.error}")
                                        onError(IllegalStateException("Video finalize error ${event.error}"))
                                    } else {
                                        AgentLog.i("video", "Recording finalized -> ${file.length()} bytes")
                                    }
                                }
                                else -> { /* Status/Pause/Resume — ignored */ }
                            }
                        }
                } catch (e: Exception) {
                    AgentLog.e("video", "Failed to start video capture", e)
                    onError(e)
                    startLatch.countDown()
                }
            }, mainExecutor)
        }

        startLatch.await(startTimeoutMs, TimeUnit.MILLISECONDS)
        return if (started) file else null
    }

    /**
     * Stops recording and blocks until the MP4 is finalized on disk. Returns
     * the finished file (or null if nothing was captured). Must not be called
     * on the main thread.
     */
    fun stop(stopTimeoutMs: Long = 10_000): File? {
        val file = outputFile ?: return null
        val stopLatch = CountDownLatch(1)

        mainExecutor.execute {
            runCatching { activeRecording?.stop() }
            activeRecording = null
            runCatching {
                cameraProvider?.unbindAll()
                lifecycleRegistry.currentState = Lifecycle.State.DESTROYED
            }
            stopLatch.countDown()
        }

        stopLatch.await(stopTimeoutMs, TimeUnit.MILLISECONDS)

        // Give the muxer a brief moment to flush the final moov atom.
        Thread.sleep(500)

        return file.takeIf { it.exists() && it.length() > 0 }
    }
}
