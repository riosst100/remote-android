package com.remoterecorder.agent.ui

import android.Manifest
import android.app.Activity
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.PowerManager
import android.provider.Settings
import android.widget.Button
import android.widget.TextView
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import com.remoterecorder.agent.R
import com.remoterecorder.agent.network.DeviceRegistrationManager
import com.remoterecorder.agent.recording.RecordingForegroundService
import com.remoterecorder.agent.util.DeviceCredentialStore
import com.remoterecorder.agent.work.HeartbeatWorker
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/**
 * The agent's only screen. Not a dashboard, not a recordings browser —
 * just what's needed for the human setting the device up: grant the mic
 * permission, register with the server, and (optionally) exempt the app
 * from battery optimization. Everything about recording itself happens
 * from the Laravel admin dashboard.
 */
class SetupActivity : AppCompatActivity() {

    private lateinit var statusText: TextView
    private lateinit var credentials: DeviceCredentialStore

    private val requestMicPermission = registerForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        refreshStatus()
        if (granted) {
            maybeStartServiceIfRegistered()
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_setup)

        credentials = DeviceCredentialStore(this)
        statusText = findViewById(R.id.statusText)

        findViewById<Button>(R.id.permissionButton).setOnClickListener {
            requestMicPermission.launch(Manifest.permission.RECORD_AUDIO)
        }

        findViewById<Button>(R.id.registerButton).setOnClickListener {
            registerDevice()
        }

        findViewById<Button>(R.id.batteryButton).setOnClickListener {
            requestBatteryOptimizationExemption()
        }

        refreshStatus()
    }

    override fun onResume() {
        super.onResume()
        refreshStatus()
    }

    private fun registerDevice() {
        if (!hasMicPermission()) {
            statusText.text = "Grant microphone permission first."
            return
        }

        statusText.text = "Registering..."

        lifecycleScope.launch {
            val result = withContext(Dispatchers.IO) {
                DeviceRegistrationManager(this@SetupActivity).register()
            }

            result.onSuccess {
                refreshStatus()
                maybeStartServiceIfRegistered()
            }.onFailure { error ->
                statusText.text = "Registration failed: ${error.message}"
            }
        }
    }

    private fun maybeStartServiceIfRegistered() {
        if (hasMicPermission() && credentials.isRegistered()) {
            RecordingForegroundService.ensureRunning(this)
            HeartbeatWorker.schedule(this)
        }
    }

    private fun refreshStatus() {
        val micStatus = if (hasMicPermission()) "granted" else "NOT granted"
        val regStatus = if (credentials.isRegistered()) "registered (device id ${credentials.serverDeviceId})" else "not registered"
        statusText.text = "Microphone permission: $micStatus\nServer registration: $regStatus"
    }

    private fun hasMicPermission(): Boolean {
        return ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED
    }

    private fun requestBatteryOptimizationExemption() {
        val powerManager = getSystemService(PowerManager::class.java)
        if (powerManager.isIgnoringBatteryOptimizations(packageName)) {
            statusText.text = "Already exempt from battery optimization."
            return
        }

        // This opens a system dialog the user must approve — we never
        // silently disable battery optimization ourselves.
        val intent = Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS).apply {
            data = Uri.parse("package:$packageName")
        }
        startActivity(intent)
    }
}
