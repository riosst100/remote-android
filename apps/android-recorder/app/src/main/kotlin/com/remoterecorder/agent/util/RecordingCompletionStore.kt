package com.remoterecorder.agent.util

import android.content.Context
import org.json.JSONArray

/**
 * Tracks recording ids whose `/complete` call has not yet succeeded —
 * regardless of origin (admin-triggered or schedule-triggered). A
 * recording only ever lands here when at least one of its parts failed
 * to upload synchronously at stop time (see
 * RecordingSessionManager.splitAndUpload); calling `/complete` before
 * every part has actually arrived server-side fails finalization there
 * ("no chunks were uploaded"/a sequence gap), so this is the retry queue
 * [RecordingCompletionSyncWorker] drains once the outstanding
 * ChunkUploadWorker jobs for those parts have had a chance to finish.
 *
 * Deliberately separate from PendingRecordingStore, which is schedule-flow
 * specific (it also tracks registration state via a DeviceSchedule that
 * admin-triggered recordings don't have, and is used to resume capture
 * bookkeeping) — this only ever needs "did /complete succeed yet?" for
 * any recording id.
 */
class RecordingCompletionStore(context: Context) {

    private val prefs = SecurePrefs.get(context)

    fun markPending(recordingId: String) {
        val all = readAll().toMutableSet()
        all.add(recordingId)
        writeAll(all)
    }

    fun markCompleted(recordingId: String) {
        val all = readAll().toMutableSet()
        all.remove(recordingId)
        writeAll(all)
    }

    fun getAllPending(): Set<String> = readAll()

    private fun readAll(): Set<String> {
        val raw = prefs.getString(KEY_PENDING, null) ?: return emptySet()
        return runCatching {
            val array = JSONArray(raw)
            (0 until array.length()).map { array.getString(it) }.toSet()
        }.getOrDefault(emptySet())
    }

    private fun writeAll(ids: Set<String>) {
        val array = JSONArray().apply { ids.forEach { put(it) } }
        prefs.edit().putString(KEY_PENDING, array.toString()).apply()
    }

    companion object {
        private const val KEY_PENDING = "pending_completions_json"
    }
}
