package com.remoterecorder.agent.network

import com.remoterecorder.agent.util.AgentLog
import com.remoterecorder.agent.util.AppConfig
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.Response
import okhttp3.WebSocket
import okhttp3.WebSocketListener
import okio.ByteString
import org.json.JSONObject
import java.util.concurrent.TimeUnit
import kotlin.math.min
import kotlin.math.pow

enum class ConnectionStatus { CONNECTING, CONNECTED, DISCONNECTED }

/**
 * Minimal client for Laravel Reverb's Pusher-compatible WebSocket protocol:
 * connects, subscribes to a private device channel (authorizing via the
 * device's own bearer-token endpoint, not the admin session endpoint), and
 * surfaces incoming command events. Hand-rolled instead of pulling in a
 * full Pusher SDK, since only a small slice of the protocol is needed here.
 *
 * Reconnection uses capped exponential backoff and never assumes the
 * caller's audio recording depends on this connection being up — that
 * relationship is intentionally one-directional (see RecordingForegroundService).
 */
class ReverbSocketClient(
    private val tokenProvider: () -> String?,
    private val onCommandEvent: (channel: String, event: String, data: JSONObject) -> Unit,
    private val onStatusChanged: (ConnectionStatus) -> Unit,
) {
    private val httpClient = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(0, TimeUnit.MILLISECONDS) // WebSocket: no read timeout
        .build()

    private var webSocket: WebSocket? = null
    private var socketId: String? = null
    private var subscribedChannel: String? = null
    private var reconnectAttempts = 0
    private var shouldReconnect = true
    private val scope = CoroutineScope(Dispatchers.IO + Job())
    private var pingJob: Job? = null

    @Volatile private var status: ConnectionStatus = ConnectionStatus.DISCONNECTED

    fun connect(deviceChannel: String) {
        subscribedChannel = deviceChannel
        shouldReconnect = true
        reconnectAttempts = 0
        openSocket()
    }

    fun disconnect() {
        shouldReconnect = false
        pingJob?.cancel()
        webSocket?.close(1000, "client_disconnect")
        webSocket = null
        updateStatus(ConnectionStatus.DISCONNECTED)
    }

    private fun openSocket() {
        updateStatus(ConnectionStatus.CONNECTING)

        val request = Request.Builder().url(AppConfig.wsUrl).build()
        webSocket = httpClient.newWebSocket(request, listener)
    }

    private val listener = object : WebSocketListener() {
        override fun onOpen(webSocket: WebSocket, response: Response) {
            AgentLog.i("websocket", "Connected to Reverb.")
        }

        override fun onMessage(webSocket: WebSocket, text: String) {
            handleMessage(text)
        }

        override fun onMessage(webSocket: WebSocket, bytes: ByteString) {
            handleMessage(bytes.utf8())
        }

        override fun onClosing(webSocket: WebSocket, code: Int, reason: String) {
            AgentLog.w("websocket", "Closing: $code $reason")
        }

        override fun onClosed(webSocket: WebSocket, code: Int, reason: String) {
            AgentLog.w("websocket", "Closed: $code $reason")
            pingJob?.cancel()
            updateStatus(ConnectionStatus.DISCONNECTED)
            scheduleReconnect()
        }

        override fun onFailure(webSocket: WebSocket, t: Throwable, response: Response?) {
            AgentLog.e("websocket", "Connection failure", t)
            pingJob?.cancel()
            updateStatus(ConnectionStatus.DISCONNECTED)
            scheduleReconnect()
        }
    }

    private fun scheduleReconnect() {
        if (!shouldReconnect) return

        reconnectAttempts += 1
        val backoffSeconds = min(2.0.pow(reconnectAttempts.coerceAtMost(6)).toLong(), 60L)
        AgentLog.i("websocket", "Reconnecting in ${backoffSeconds}s (attempt $reconnectAttempts).")

        scope.launch {
            delay(backoffSeconds * 1000L)
            if (shouldReconnect) openSocket()
        }
    }

    private fun handleMessage(text: String) {
        val message = runCatching { JSONObject(text) }.getOrNull() ?: return
        val event = message.optString("event")

        when (event) {
            "pusher:connection_established" -> handleConnectionEstablished(message)
            "pusher:ping" -> sendPong()
            "pusher:error" -> AgentLog.e("websocket", "Server error: ${message.optJSONObject("data")}")
            "pusher_internal:subscription_succeeded" -> {
                AgentLog.i("websocket", "Subscribed to ${message.optString("channel")}")
                reconnectAttempts = 0
                updateStatus(ConnectionStatus.CONNECTED)
            }
            else -> {
                val channel = message.optString("channel")
                val rawData = message.opt("data")
                val data = when (rawData) {
                    is String -> runCatching { JSONObject(rawData) }.getOrDefault(JSONObject())
                    is JSONObject -> rawData
                    else -> JSONObject()
                }
                if (channel.isNotBlank() && event.isNotBlank()) {
                    onCommandEvent(channel, event, data)
                }
            }
        }
    }

    private fun handleConnectionEstablished(message: JSONObject) {
        val data = JSONObject(message.getString("data"))
        socketId = data.getString("socket_id")
        AgentLog.i("websocket", "Connection established, socket_id=$socketId")

        subscribedChannel?.let { subscribe(it) }
        startHeartbeat()
    }

    private fun subscribe(channel: String) {
        val auth = authorizeChannel(channel) ?: run {
            AgentLog.e("websocket", "Channel auth failed for $channel; will retry on reconnect.")
            return
        }

        val subscribeMessage = JSONObject().apply {
            put("event", "pusher:subscribe")
            put("data", JSONObject().apply {
                put("channel", channel)
                put("auth", auth)
            })
        }

        webSocket?.send(subscribeMessage.toString())
    }

    private fun authorizeChannel(channel: String): String? {
        val token = tokenProvider() ?: return null
        val sid = socketId ?: return null

        val body = JSONObject().apply {
            put("socket_id", sid)
            put("channel_name", channel)
        }

        val request = Request.Builder()
            .url(AppConfig.broadcastAuthUrl)
            .addHeader("Authorization", "Bearer $token")
            .addHeader("Accept", "application/json")
            .post(body.toString().toRequestBody("application/json; charset=utf-8".toMediaType()))
            .build()

        return runCatching {
            httpClient.newCall(request).execute().use { response ->
                if (!response.isSuccessful) return null
                val json = JSONObject(response.body?.string().orEmpty())
                json.getString("auth")
            }
        }.getOrNull()
    }

    private fun sendPong() {
        webSocket?.send(JSONObject().apply { put("event", "pusher:pong") }.toString())
    }

    private fun startHeartbeat() {
        pingJob?.cancel()
        pingJob = scope.launch {
            while (true) {
                delay(30_000)
                webSocket?.send(JSONObject().apply { put("event", "pusher:ping") }.toString())
            }
        }
    }

    private fun updateStatus(newStatus: ConnectionStatus) {
        status = newStatus
        onStatusChanged(newStatus)
    }
}
