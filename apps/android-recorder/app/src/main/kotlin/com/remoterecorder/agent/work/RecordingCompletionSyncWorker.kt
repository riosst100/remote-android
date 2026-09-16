package com.remoterecorder.agent.work

import android.content.Context
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import com.remoterecorder.agent.network.ApiClient
import com.remoterecorder.agent.util.AgentLog
import com.remoterecorder.agent.util.DeviceCredentialStore
import com.remoterecorder.agent.util.RecordingCompletionStore
import java.util.concurrent.TimeUnit

/**
 * Retries `/complete` for any recording whose parts weren't all uploaded
 * synchronously at stop time (see RecordingSessionManager.splitAndUpload)
 * — by the time this runs, the ChunkUploadWorker jobs for those parts have
 * had a chance to actually land server-side, so a call that would have
 * failed right at stop() ("no chunks were uploaded"/a sequence gap) can
 * now succeed. `/complete` is idempotent server-side, so retrying an
 * already-completed recording is harmless.
 */
class RecordingCompletionSyncWorker(appContext: Context, params: WorkerParameters) : CoroutineWorker(appContext, params) {

    override suspend fun doWork(): Result {
        val credentials = DeviceCredentialStore(applicationContext)
        if (!credentials.isRegistered()) return Result.success()

        val store = RecordingCompletionStore(applicationContext)
        val client = ApiClient { credentials.token }
        var anyFailed = false

        for (recordingId in store.getAllPending()) {
            runCatching { client.completeRecording(recordingId) }
                .onSuccess { store.markCompleted(recordingId) }
                .onFailure {
                    anyFailed = true
                    AgentLog.w("recording_completion_sync", "Retrying /complete for $recordingId failed; will retry again later.", it)
                }
        }

        return if (anyFailed) Result.retry() else Result.success()
    }

    companion object {
        private const val WORK_NAME = "recording_completion_sync"

        fun schedule(context: Context) {
            val constraints = Constraints.Builder()
                .setRequiredNetworkType(NetworkType.CONNECTED)
                .build()

            val request = PeriodicWorkRequestBuilder<RecordingCompletionSyncWorker>(15, TimeUnit.MINUTES)
                .setConstraints(constraints)
                .build()

            WorkManager.getInstance(context).enqueueUniquePeriodicWork(
                WORK_NAME,
                ExistingPeriodicWorkPolicy.KEEP,
                request,
            )
        }

        /** Nudges an immediate retry attempt rather than waiting for the next periodic tick — used right after a chunk upload succeeds. */
        fun runNow(context: Context) {
            val request = androidx.work.OneTimeWorkRequestBuilder<RecordingCompletionSyncWorker>()
                .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
                .build()
            WorkManager.getInstance(context).enqueueUniqueWork(
                "${WORK_NAME}_immediate",
                androidx.work.ExistingWorkPolicy.KEEP,
                request,
            )
        }
    }
}
