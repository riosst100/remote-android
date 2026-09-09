package com.remoterecorder.agent.util

import android.content.Context
import com.remoterecorder.agent.model.DeviceSchedule
import com.remoterecorder.agent.model.RecordingConfig
import org.json.JSONArray
import org.json.JSONObject
import java.time.Instant

/**
 * Tracks the small set of locally-started (schedule-triggered) recordings
 * not yet confirmed `/complete`d server-side. Backs the offline-safe
 * registration flow: a recording started while offline is fully captured
 * locally regardless of connectivity, and this store is what
 * `PendingRecordingSyncWorker` reads to recover registration/completion
 * once the device is back online.
 *
 * Same `SecurePrefs`-backed, single-JSON-blob pattern as `ScheduleStore`
 * and `DeviceCredentialStore` — the set of pending recordings is always
 * small (bounded by how many schedule-triggered recordings can be
 * in-flight/unconfirmed at once), so no relational storage is needed.
 */
data class PendingRecording(
    val localId: String,
    val scheduleId: Long?,
    val preset: String,
    val startedAtEpochMillis: Long,
    val encoder: String,
    val sampleRate: Int,
    val bitrate: Int,
    val channels: Int,
    val registeredServerSide: Boolean,
    val finished: Boolean,
    val completed: Boolean,
)

class PendingRecordingStore(context: Context) {

    private val prefs = SecurePrefs.get(context)

    fun markStarted(id: String, schedule: DeviceSchedule, startedAt: Instant, config: RecordingConfig) {
        val all = readAll().toMutableMap()
        all[id] = PendingRecording(
            localId = id,
            scheduleId = schedule.id,
            preset = schedule.preset,
            startedAtEpochMillis = startedAt.toEpochMilli(),
            encoder = config.encoder.name.lowercase(),
            sampleRate = config.sampleRate,
            bitrate = config.bitrate,
            channels = config.channels,
            registeredServerSide = false,
            finished = false,
            completed = false,
        )
        writeAll(all)
    }

    fun markRegistered(id: String) = update(id) { it.copy(registeredServerSide = true) }

    fun markFinishedRecording(id: String) = update(id) { it.copy(finished = true) }

    fun markCompleted(id: String) = update(id) { it.copy(completed = true) }

    /** All recordings not yet fully completed server-side; prunes completed entries as a side effect. */
    fun getAllPending(): List<PendingRecording> {
        val all = readAll()
        val (completed, pending) = all.values.partition { it.completed }
        if (completed.isNotEmpty()) {
            writeAll(all.filterValues { !it.completed })
        }
        return pending
    }

    private fun update(id: String, transform: (PendingRecording) -> PendingRecording) {
        val all = readAll().toMutableMap()
        val existing = all[id] ?: return
        all[id] = transform(existing)
        writeAll(all)
    }

    private fun readAll(): Map<String, PendingRecording> {
        val raw = prefs.getString(KEY_PENDING, null) ?: return emptyMap()
        return runCatching {
            val array = JSONArray(raw)
            (0 until array.length()).mapNotNull { index ->
                runCatching { fromJson(array.getJSONObject(index)) }.getOrNull()
            }.associateBy { it.localId }
        }.getOrDefault(emptyMap())
    }

    private fun writeAll(all: Map<String, PendingRecording>) {
        val array = JSONArray().apply { all.values.forEach { put(toJson(it)) } }
        prefs.edit().putString(KEY_PENDING, array.toString()).apply()
    }

    private fun toJson(p: PendingRecording): JSONObject = JSONObject().apply {
        put("local_id", p.localId)
        p.scheduleId?.let { put("schedule_id", it) }
        put("preset", p.preset)
        put("started_at_epoch_millis", p.startedAtEpochMillis)
        put("encoder", p.encoder)
        put("sample_rate", p.sampleRate)
        put("bitrate", p.bitrate)
        put("channels", p.channels)
        put("registered_server_side", p.registeredServerSide)
        put("finished", p.finished)
        put("completed", p.completed)
    }

    private fun fromJson(json: JSONObject): PendingRecording = PendingRecording(
        localId = json.getString("local_id"),
        scheduleId = if (json.has("schedule_id")) json.getLong("schedule_id") else null,
        preset = json.getString("preset"),
        startedAtEpochMillis = json.getLong("started_at_epoch_millis"),
        encoder = json.getString("encoder"),
        sampleRate = json.getInt("sample_rate"),
        bitrate = json.getInt("bitrate"),
        channels = json.getInt("channels"),
        registeredServerSide = json.optBoolean("registered_server_side", false),
        finished = json.optBoolean("finished", false),
        completed = json.optBoolean("completed", false),
    )

    companion object {
        private const val KEY_PENDING = "pending_recordings_json"
    }
}
