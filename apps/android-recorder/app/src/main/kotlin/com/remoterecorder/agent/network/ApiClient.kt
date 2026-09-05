package com.remoterecorder.agent.network

import com.remoterecorder.agent.model.AudioCapabilities
import com.remoterecorder.agent.util.AgentLog
import com.remoterecorder.agent.util.AppConfig
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.Response
import org.json.JSONObject
import java.io.File
import java.util.concurrent.TimeUnit

class ApiException(val code: String?, message: String) : Exception(message)

/**
 * Thin, dependency-light HTTP client for the Laravel API. Deliberately not
 * Retrofit — the API surface is small enough that plain OkHttp + org.json
 * keeps the dependency footprint down, per the "avoid unnecessary
 * third-party frameworks" guidance.
 */
class ApiClient(private val tokenProvider: () -> String?) {

    private val client = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(30, TimeUnit.SECONDS)
        .writeTimeout(60, TimeUnit.SECONDS)
        .build()

    private val jsonMediaType = "application/json; charset=utf-8".toMediaType()

    fun registerDevice(deviceUuid: String, name: String, manufacturer: String, model: String, androidVersion: String, appVersion: String, capabilities: AudioCapabilities): JSONObject {
        val body = JSONObject().apply {
            put("device_uuid", deviceUuid)
            put("device_name", name)
            put("manufacturer", manufacturer)
            put("model", model)
            put("android_version", androidVersion)
            put("app_version", appVersion)
            put("audio_capabilities", capabilities.toJson())
        }

        val request = Request.Builder()
            .url("${AppConfig.apiBaseUrl}/api/devices/register")
            .post(body.toString().toRequestBody(jsonMediaType))
            .build()

        return executeJson(request, authenticated = false)
    }

    fun heartbeat(status: String?): JSONObject {
        val body = JSONObject().apply { status?.let { put("status", it) } }
        val request = authedRequestBuilder("/api/devices/heartbeat")
            .post(body.toString().toRequestBody(jsonMediaType))
            .build()

        return executeJson(request, authenticated = true)
    }

    fun reportError(errorCode: String, message: String?, recordingId: String?) {
        val body = JSONObject().apply {
            put("error_code", errorCode)
            message?.let { put("message", it) }
            recordingId?.let { put("recording_id", it) }
        }
        val request = authedRequestBuilder("/api/devices/error")
            .post(body.toString().toRequestBody(jsonMediaType))
            .build()

        runCatching { executeJson(request, authenticated = true) }
            .onFailure { AgentLog.e("api", "Failed to report error to server", it) }
    }

    fun acknowledgeCommand(commandId: String, event: String, configuration: JSONObject? = null, errorCode: String? = null, errorMessage: String? = null) {
        val body = JSONObject().apply {
            put("event", event)
            configuration?.let { put("configuration", it) }
            errorCode?.let { put("error_code", it) }
            errorMessage?.let { put("error_message", it) }
        }
        val request = authedRequestBuilder("/api/commands/$commandId/ack")
            .post(body.toString().toRequestBody(jsonMediaType))
            .build()

        executeJson(request, authenticated = true)
    }

    fun uploadChunk(recordingId: String, chunkNumber: Int, checksum: String, durationSeconds: Int, file: File, mimeType: String): JSONObject {
        val multipart = MultipartBody.Builder()
            .setType(MultipartBody.FORM)
            .addFormDataPart("chunk_number", chunkNumber.toString())
            .addFormDataPart("checksum", checksum)
            .addFormDataPart("duration", durationSeconds.toString())
            .addFormDataPart("file", file.name, file.asRequestBody(mimeType.toMediaType()))
            .build()

        val request = authedRequestBuilder("/api/recordings/$recordingId/chunks")
            .post(multipart)
            .build()

        return executeJson(request, authenticated = true)
    }

    fun completeRecording(recordingId: String): JSONObject {
        val request = authedRequestBuilder("/api/recordings/$recordingId/complete")
            .post("".toRequestBody(null))
            .build()

        return executeJson(request, authenticated = true)
    }

    private fun authedRequestBuilder(path: String): Request.Builder {
        val token = tokenProvider() ?: throw ApiException("NOT_REGISTERED", "Device has no API token yet.")
        return Request.Builder()
            .url("${AppConfig.apiBaseUrl}$path")
            .addHeader("Authorization", "Bearer $token")
    }

    private fun executeJson(request: Request, authenticated: Boolean): JSONObject {
        client.newCall(request).execute().use { response: Response ->
            val bodyString = response.body?.string().orEmpty()
            val json = if (bodyString.isNotBlank()) runCatching { JSONObject(bodyString) }.getOrDefault(JSONObject()) else JSONObject()

            if (!response.isSuccessful) {
                val message = json.optString("message", "Request failed with HTTP ${response.code}")
                val errorCode = if (json.has("error")) json.optString("error") else null
                AgentLog.w("api", "Request to ${request.url} failed: $message")
                throw ApiException(errorCode, message)
            }

            return json
        }
    }
}
