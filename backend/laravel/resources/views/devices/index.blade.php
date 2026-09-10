@extends('layouts.app')

@section('title', 'Devices')

@section('content')
<h2 style="margin-top:0;">Devices <span id="realtime-indicator" class="muted" style="font-size:12px;font-weight:400;"></span></h2>
<div class="card">
    <table>
        <thead>
            <tr><th>Device</th><th>Model</th><th>Android</th><th>Status</th><th>Last Seen</th><th>Current Recording</th><th>Duration</th><th>Actions</th></tr>
        </thead>
        <tbody id="devices-table-body">
            @forelse ($devices as $device)
            @php $active = $device->recordings->first(fn($r) => !$r->status->isTerminal()); @endphp
            @php
                $lastRecording = $device->recordings->first();
                $isLive = $active && $active->started_at && ! in_array($active->status->value, ['STARTING'], true);
            @endphp
            <tr data-device-id="{{ $device->id }}"
                data-recording-uuid="{{ $active->uuid ?? '' }}"
                data-started-at="{{ $isLive ? $active->started_at->toIso8601String() : '' }}"
                data-last-duration="{{ (!$active && $lastRecording?->duration) ? $lastRecording->duration : '' }}">
                <td><a href="{{ route('devices.show', $device) }}">{{ $device->name ?? $device->device_uuid }}</a></td>
                <td>{{ $device->manufacturer }} {{ $device->model }}</td>
                <td>{{ $device->android_version }}</td>
                <td class="device-status"><span class="badge {{ $device->status->value }}">{{ $device->status->value }}</span></td>
                <td class="device-last-seen muted">{{ optional($device->last_seen_at)->diffForHumans() ?? 'never' }}</td>
                <td class="device-recording">
                    @if ($active)
                        <a href="{{ route('recordings.show', $active) }}">{{ substr($active->uuid, 0, 8) }} ({{ $active->status->value }})</a>
                    @else
                        <span class="muted">—</span>
                    @endif
                </td>
                <td class="device-duration muted">
                    @if ($isLive)
                        <span class="live-timer">00:00:00</span>
                    @elseif ($lastRecording?->duration)
                        {{ gmdate('H:i:s', $lastRecording->duration) }}
                    @else
                        —
                    @endif
                </td>
                <td>
                    <select class="preset-select" data-device="{{ $device->id }}" {{ $active ? 'disabled' : '' }} style="max-width:220px;">
                        @foreach ($presets as $preset)
                            @php $config = $presetConfigsByDevice[$device->id][$preset->value]; @endphp
                            <option value="{{ $preset->value }}" {{ $preset->value === 'HIGH' ? 'selected' : '' }}>
                                {{ $preset->value }} — {{ strtoupper($config['encoder']) }} {{ number_format($config['sample_rate'] / 1000, 1) }}kHz/{{ $config['bitrate'] / 1000 }}kbps
                            </option>
                        @endforeach
                    </select>
                    <button class="primary start-btn" data-device="{{ $device->id }}" {{ $active || $device->status->value === 'OFFLINE' ? 'disabled' : '' }}>Start</button>
                    <button class="danger stop-btn" data-recording="{{ $active->uuid ?? '' }}" {{ $active ? '' : 'disabled' }}>Stop</button>
                    <form method="POST" action="{{ route('devices.destroy', $device) }}" style="display:inline;"
                          onsubmit="return confirm('Delete this device and ALL of its recordings? This cannot be undone.');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="danger" {{ $active ? 'disabled title="Stop the active recording first."' : '' }}>Delete</button>
                    </form>
                </td>
            </tr>
            @empty
            <tr><td colspan="8" class="muted">No devices have registered yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('click', async (e) => {
    if (e.target.classList.contains('start-btn')) {
        const deviceId = e.target.dataset.device;
        const preset = document.querySelector(`.preset-select[data-device="${deviceId}"]`).value;
        e.target.disabled = true;
        try {
            await window.apiFetch('/api/recordings/start', { method: 'POST', body: JSON.stringify({ device_id: Number(deviceId), preset }) });
            window.showToast('Recording start requested.');
        } catch (err) {
            window.showToast(err.message, true);
            e.target.disabled = false;
        }
    }

    if (e.target.classList.contains('stop-btn')) {
        const recordingUuid = e.target.dataset.recording;
        if (!recordingUuid) return;
        e.target.disabled = true;
        try {
            await window.apiFetch(`/api/recordings/${recordingUuid}/stop`, { method: 'POST' });
            window.showToast('Recording stop requested.');
        } catch (err) {
            window.showToast(err.message, true);
            e.target.disabled = false;
        }
    }
});

function rowForDevice(deviceId) {
    return document.querySelector(`#devices-table-body tr[data-device-id="${deviceId}"]`);
}

function setDeviceStatus(deviceId, status, lastSeenLabel) {
    const row = rowForDevice(deviceId);
    if (!row) return;

    const badge = row.querySelector('.device-status .badge');
    badge.textContent = status;
    badge.className = `badge ${status}`;

    if (lastSeenLabel) {
        row.querySelector('.device-last-seen').textContent = lastSeenLabel;
    }

    const startBtn = row.querySelector('.start-btn');
    const hasActiveRecording = !!row.dataset.recordingUuid;
    startBtn.disabled = hasActiveRecording || status === 'OFFLINE';
}

function formatHms(totalSeconds) {
    const s = Math.max(0, Math.floor(totalSeconds));
    const h = String(Math.floor(s / 3600)).padStart(2, '0');
    const m = String(Math.floor((s % 3600) / 60)).padStart(2, '0');
    const sec = String(s % 60).padStart(2, '0');
    return `${h}:${m}:${sec}`;
}

/**
 * @param recording null to clear (no active recording), or
 *   { uuid, status, startedAt?: ISO8601 string, finalDuration?: seconds }
 */
function setDeviceRecording(deviceId, recording) {
    const row = rowForDevice(deviceId);
    if (!row) return;

    const cell = row.querySelector('.device-recording');
    const durationCell = row.querySelector('.device-duration');
    const startBtn = row.querySelector('.start-btn');
    const stopBtn = row.querySelector('.stop-btn');
    const presetSelect = row.querySelector('.preset-select');
    const isTerminal = !recording || ['COMPLETED', 'FAILED'].includes(recording.status);

    if (recording && !isTerminal) {
        row.dataset.recordingUuid = recording.uuid;
        cell.innerHTML = `<a href="/recordings/${recording.uuid}">${recording.uuid.slice(0, 8)} (${recording.status})</a>`;
        stopBtn.dataset.recording = recording.uuid;
        stopBtn.disabled = false;
        startBtn.disabled = true;
        presetSelect.disabled = true;

        // The timer only starts ticking once the device has actually
        // confirmed recording (RecordingStarted carries started_at);
        // STARTING has no meaningful elapsed time yet.
        if (recording.startedAt) {
            row.dataset.startedAt = recording.startedAt;
            durationCell.innerHTML = '<span class="live-timer">00:00:00</span>';
        } else if (!row.dataset.startedAt) {
            durationCell.textContent = '—';
        }
    } else {
        row.dataset.recordingUuid = '';
        row.dataset.startedAt = '';
        cell.innerHTML = '<span class="muted">—</span>';
        stopBtn.dataset.recording = '';
        stopBtn.disabled = true;
        presetSelect.disabled = false;
        durationCell.textContent = recording?.finalDuration != null ? formatHms(recording.finalDuration) : '—';
        const badge = row.querySelector('.device-status .badge');
        startBtn.disabled = badge.textContent === 'OFFLINE';
    }
}

// One shared interval updates every visible live timer — cheaper than a
// setInterval per row and keeps all timers ticking in lockstep.
setInterval(() => {
    document.querySelectorAll('#devices-table-body tr[data-started-at]:not([data-started-at=""])').forEach((row) => {
        const timerEl = row.querySelector('.live-timer');
        if (!timerEl) return;
        const elapsedSeconds = (Date.now() - new Date(row.dataset.startedAt).getTime()) / 1000;
        timerEl.textContent = formatHms(elapsedSeconds);
    });
}, 1000);

window.realtimeReady?.then((pusher) => {
    const channel = pusher.subscribe('private-admin.devices');

    channel.bind('DeviceStatusChanged', (data) => {
        setDeviceStatus(data.device_id, data.status, 'just now');
    });

    channel.bind('DeviceConnected', (data) => {
        setDeviceStatus(data.device_id, data.status, 'just now');
    });

    channel.bind('DeviceDisconnected', (data) => {
        setDeviceStatus(data.device_id, 'OFFLINE', 'just now');
    });

    const recordingsChannel = pusher.subscribe('private-admin.recordings');

    const handleRecordingEvent = (data) => {
        setDeviceRecording(data.device_id, { uuid: data.recording_id, status: data.status ?? 'RECORDING' });
    };

    // RecordingStartRequested/StopRequested carry device_id as the
    // device's UUID (that's the Android command contract) rather than the
    // numeric row id every other event uses, so they expose
    // admin_device_id specifically for this kind of dashboard lookup.
    recordingsChannel.bind('RecordingStartRequested', (data) => {
        setDeviceRecording(data.admin_device_id, { uuid: data.recording_id, status: 'STARTING' });
    });
    recordingsChannel.bind('RecordingStarted', (data) => {
        setDeviceRecording(data.device_id, { uuid: data.recording_id, status: data.status, startedAt: data.started_at });
    });
    recordingsChannel.bind('RecordingStatusChanged', handleRecordingEvent);
    recordingsChannel.bind('RecordingStopRequested', (data) => {
        setDeviceRecording(data.admin_device_id, { uuid: data.recording_id, status: 'STOPPING' });
    });
    recordingsChannel.bind('RecordingStopped', (data) => {
        setDeviceRecording(data.device_id, { uuid: data.recording_id, status: 'PROCESSING' });
    });
    recordingsChannel.bind('RecordingCompleted', (data) => {
        setDeviceRecording(data.device_id, { status: 'COMPLETED', finalDuration: data.duration });
        window.showToast(`Recording ${data.recording_id.slice(0, 8)} completed.`);
    });
    recordingsChannel.bind('RecordingFailed', (data) => {
        setDeviceRecording(data.device_id, null);
        window.showToast(`Recording ${data.recording_id.slice(0, 8)} failed: ${data.error_message ?? 'unknown error'}`, true);
    });

    document.getElementById('realtime-indicator').textContent = '● live';
});
</script>
@endpush
