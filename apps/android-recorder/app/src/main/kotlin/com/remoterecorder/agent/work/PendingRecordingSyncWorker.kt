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
import com.remoterecorder.agent.util.PendingRecordingStore
import java.time.Instant
import java.util.concurrent.TimeUnit

/**
 * Recovery job for schedule-triggered recordings that could not be
 * registered/completed with the server at the time (device offline).
 * Because `createRecording` is idempotent on `client_recording_id` and
 * `/complete` is already idempotent, this job is safe to run repeatedly
 * and redundantly. Chunks themselves need no extra recovery here — they're
 * independent WorkManager jobs (`ChunkUploadWorker`) that already persist
 * across process death.
 */
class PendingRecordingSyncWorker(appContext: Context, params: WorkerParameters) : CoroutineWorker(appContext, params) {

    override suspend fun doWork(): Result {
        val credentials = DeviceCredentialStore(applicationContext)
        if (!credentials.isRegistered()) return Result.success()

        val store = PendingRecordingStore(applicationContext)
        val client = ApiClient { credentials.token }

        for (pending in store.getAllPending()) {
            if (!pending.registeredServerSide) {
                val result = runCatching {
                    client.createRecording(
                        clientRecordingId = pending.localId,
                        preset = pending.preset,
                        startedAt = Instant.ofEpochMilli(pending.startedAtEpochMillis).toString(),
                        encoder = pending.encoder,
                        sampleRate = pending.sampleRate,
                        bitrate = pending.bitrate,
                        channels = pending.channels,
                        scheduleId = pending.scheduleId,
                    )
                }
                if (result.isSuccess) {
                    store.markRegistered(pending.localId)
                } else {
                    AgentLog.w("pending_recording_sync", "Failed to register ${pending.localId}; will retry.", result.exceptionOrNull())
                    return Result.retry() // keep the whole batch simple; N is always small
                }
            }

            if (pending.finished && !pending.completed) {
                runCatching { client.completeRecording(pending.localId) }
                    .onSuccess { store.markCompleted(pending.localId) }
                    .onFailure { AgentLog.w("pending_recording_sync", "Failed to complete ${pending.localId}; will retry.", it) }
            }
        }

        return Result.success()
    }

    companion object {
        private const val WORK_NAME = "pending_recording_sync"

        fun schedule(context: Context) {
            val constraints = Constraints.Builder()
                .setRequiredNetworkType(NetworkType.CONNECTED)
                .build()

            val request = PeriodicWorkRequestBuilder<PendingRecordingSyncWorker>(30, TimeUnit.MINUTES)
                .setConstraints(constraints)
                .build()

            WorkManager.getInstance(context).enqueueUniquePeriodicWork(
                WORK_NAME,
                ExistingPeriodicWorkPolicy.KEEP,
                request,
            )
        }
    }
}
