package com.remoterecorder.agent.work

import android.content.Context
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.Data
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import androidx.work.workDataOf
import com.remoterecorder.agent.model.ErrorCode
import com.remoterecorder.agent.network.ApiClient
import com.remoterecorder.agent.network.ApiException
import com.remoterecorder.agent.recording.ChunkFileStore
import com.remoterecorder.agent.util.AgentLog
import com.remoterecorder.agent.util.DeviceCredentialStore
import java.io.File
import java.util.concurrent.TimeUnit

/**
 * Uploads one audio chunk with WorkManager-managed retry/backoff. Using
 * WorkManager (rather than a plain coroutine retry loop) means an upload
 * that's still pending when the process dies is picked back up once the
 * OS restarts work, without any bespoke persistence of our own.
 *
 * The chunk's temp file is only deleted after the server confirms the
 * upload — that's the enforcement point for "never lose an
 * already-uploaded chunk, and never delete one that might not have
 * arrived."
 */
class ChunkUploadWorker(appContext: Context, params: WorkerParameters) : CoroutineWorker(appContext, params) {

    override suspend fun doWork(): Result {
        val recordingId = inputData.getString(KEY_RECORDING_ID) ?: return Result.failure()
        val chunkNumber = inputData.getInt(KEY_CHUNK_NUMBER, -1)
        val filePath = inputData.getString(KEY_FILE_PATH) ?: return Result.failure()
        val checksum = inputData.getString(KEY_CHECKSUM) ?: return Result.failure()
        val duration = inputData.getInt(KEY_DURATION, 0)
        val mimeType = inputData.getString(KEY_MIME_TYPE) ?: "audio/aac"

        val file = File(filePath)
        if (!file.exists()) {
            // Nothing we can do — the chunk file is gone (e.g. cache was
            // cleared by the OS under storage pressure). Documented data-loss
            // trade-off: we never kept a second copy of this audio anywhere.
            AgentLog.e("upload", "Chunk file missing, cannot upload: $filePath")
            return Result.failure()
        }

        val credentials = DeviceCredentialStore(applicationContext)
        val client = ApiClient { credentials.token }

        return try {
            client.uploadChunk(recordingId, chunkNumber, checksum, duration, file, mimeType)
            AgentLog.i("upload", "Chunk #$chunkNumber uploaded for recording $recordingId.")
            ChunkFileStore.delete(file)
            Result.success()
        } catch (e: ApiException) {
            if (isClientError(e)) {
                AgentLog.e("upload", "Chunk #$chunkNumber rejected permanently: ${e.message}")
                runCatching { client.reportError(ErrorCode.UPLOAD_ERROR.name, e.message, recordingId) }
                Result.failure()
            } else {
                AgentLog.w("upload", "Chunk #$chunkNumber upload failed, will retry: ${e.message}")
                Result.retry()
            }
        } catch (e: Exception) {
            AgentLog.w("upload", "Chunk #$chunkNumber upload failed (network), will retry.", e)
            Result.retry()
        }
    }

    private fun isClientError(e: ApiException): Boolean = e.code == "UPLOAD_ERROR"

    companion object {
        private const val KEY_RECORDING_ID = "recording_id"
        private const val KEY_CHUNK_NUMBER = "chunk_number"
        private const val KEY_FILE_PATH = "file_path"
        private const val KEY_CHECKSUM = "checksum"
        private const val KEY_DURATION = "duration"
        private const val KEY_MIME_TYPE = "mime_type"

        fun enqueue(context: Context, recordingId: String, chunkNumber: Int, file: File, checksum: String, durationSeconds: Int, mimeType: String) {
            val data: Data = workDataOf(
                KEY_RECORDING_ID to recordingId,
                KEY_CHUNK_NUMBER to chunkNumber,
                KEY_FILE_PATH to file.absolutePath,
                KEY_CHECKSUM to checksum,
                KEY_DURATION to durationSeconds,
                KEY_MIME_TYPE to mimeType,
            )

            val constraints = Constraints.Builder()
                .setRequiredNetworkType(NetworkType.CONNECTED)
                .build()

            val request = OneTimeWorkRequestBuilder<ChunkUploadWorker>()
                .setInputData(data)
                .setConstraints(constraints)
                .setBackoffCriteria(androidx.work.BackoffPolicy.EXPONENTIAL, 10, TimeUnit.SECONDS)
                .addTag(TAG_UPLOAD)
                .addTag("recording:$recordingId")
                .build()

            // Unique per (recording, chunk) so a retriggered enqueue (e.g. app
            // restart while chunks are still pending) can never create a
            // duplicate upload for the same chunk.
            WorkManager.getInstance(context).enqueueUniqueWork(
                "upload-$recordingId-$chunkNumber",
                ExistingWorkPolicy.KEEP,
                request,
            )
        }

        const val TAG_UPLOAD = "chunk_upload"
    }
}
