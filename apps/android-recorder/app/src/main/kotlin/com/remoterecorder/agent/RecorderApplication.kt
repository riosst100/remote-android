package com.remoterecorder.agent

import android.app.Application
import com.remoterecorder.agent.recording.RecordingForegroundService
import com.remoterecorder.agent.util.DeviceCredentialStore
import com.remoterecorder.agent.work.HeartbeatWorker
import com.remoterecorder.agent.work.PendingRecordingSyncWorker
import com.remoterecorder.agent.work.ScheduleSyncWorker

class RecorderApplication : Application() {

    override fun onCreate() {
        super.onCreate()

        // Orphaned chunk files from a previous crash before upload could be
        // enqueued are left alone here — ChunkUploadWorker's WorkManager
        // jobs survive process death and still deliver them on restart.

        val credentials = DeviceCredentialStore(this)
        if (credentials.isRegistered()) {
            RecordingForegroundService.ensureRunning(this)
            HeartbeatWorker.schedule(this)
            ScheduleSyncWorker.schedule(this)
            PendingRecordingSyncWorker.schedule(this)
        }
    }
}
