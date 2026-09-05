@extends('layouts.app')

@section('title', $device->name ?? $device->device_uuid)

@section('content')
<p><a href="{{ route('devices.index') }}">&larr; Devices</a></p>
<h2 style="margin-top:0;">{{ $device->name ?? 'Unnamed device' }}</h2>

<div class="grid cols-2" style="margin-bottom:24px;">
    <div class="card">
        <h3 style="margin-top:0;">Device Information</h3>
        <table>
            <tr><th>UUID</th><td><code>{{ $device->device_uuid }}</code></td></tr>
            <tr><th>Manufacturer</th><td>{{ $device->manufacturer ?? '—' }}</td></tr>
            <tr><th>Model</th><td>{{ $device->model ?? '—' }}</td></tr>
            <tr><th>Android Version</th><td>{{ $device->android_version ?? '—' }}</td></tr>
            <tr><th>App Version</th><td>{{ $device->app_version ?? '—' }}</td></tr>
            <tr><th>Status</th><td><span class="badge {{ $device->status->value }}">{{ $device->status->value }}</span></td></tr>
            <tr><th>Last Heartbeat</th><td>{{ optional($device->last_seen_at)->diffForHumans() ?? 'never' }}</td></tr>
        </table>
    </div>
    <div class="card">
        <h3 style="margin-top:0;">Audio Capabilities</h3>
        @if ($device->capabilities)
        <table>
            <tr><th>Encoders</th><td>{{ implode(', ', $device->capabilities['encoders'] ?? []) }}</td></tr>
            <tr><th>Sample Rates</th><td>{{ implode(', ', $device->capabilities['sample_rates'] ?? []) }}</td></tr>
            <tr><th>Channels</th><td>{{ implode(', ', $device->capabilities['channels'] ?? []) }}</td></tr>
            <tr><th>Bitrates</th><td>{{ implode(', ', $device->capabilities['bitrates'] ?? []) }}</td></tr>
        </table>
        @else
        <p class="muted">No capability data reported yet.</p>
        @endif
    </div>
</div>

<div class="card" style="margin-bottom:24px;">
    <h3 style="margin-top:0;">Start a Recording</h3>
    <p class="muted">Expected configuration per preset, resolved against this device's reported capabilities. The device performs its own final fallback if the proposed configuration turns out to be unsupported.</p>
    <table style="margin-bottom:16px;">
        <thead><tr><th>Preset</th><th>Encoder</th><th>Sample Rate</th><th>Bitrate</th><th>Channels</th></tr></thead>
        <tbody>
        @foreach ($previewConfigs as $preset => $config)
            <tr>
                <td>{{ $preset }}</td>
                <td>{{ $config['encoder'] }}</td>
                <td>{{ $config['sample_rate'] }} Hz</td>
                <td>{{ $config['bitrate'] ? number_format($config['bitrate'] / 1000).' kbps' : 'lossless' }}</td>
                <td>{{ $config['channels'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    @php $active = $device->recordings->first(fn($r) => !$r->status->isTerminal()); @endphp
    <select id="preset-select" {{ $active ? 'disabled' : '' }}>
        @foreach ($presets as $preset)
            <option value="{{ $preset->value }}" {{ $preset->value === 'HIGH' ? 'selected' : '' }}>{{ $preset->value }}</option>
        @endforeach
    </select>
    <button id="start-btn" class="primary" {{ $active || $device->status->value === 'OFFLINE' ? 'disabled' : '' }}>Start Recording</button>
    <button id="stop-btn" class="danger" data-recording="{{ $active->uuid ?? '' }}" {{ $active ? '' : 'disabled' }}>Stop Recording</button>
</div>

<div class="card">
    <h3 style="margin-top:0;">Recording History</h3>
    <table>
        <thead><tr><th>Recording</th><th>Status</th><th>Preset</th><th>Started</th><th>Duration</th><th></th></tr></thead>
        <tbody>
        @forelse ($device->recordings as $recording)
            <tr>
                <td><code>{{ substr($recording->uuid, 0, 8) }}</code></td>
                <td><span class="badge {{ $recording->status->value }}">{{ $recording->status->value }}</span></td>
                <td>{{ $recording->preset->value }}</td>
                <td class="muted">{{ optional($recording->started_at)->diffForHumans() ?? '—' }}</td>
                <td>{{ $recording->duration ? gmdate('H:i:s', $recording->duration) : '—' }}</td>
                <td><a href="{{ route('recordings.show', $recording) }}">View</a></td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">No recordings for this device yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection

@push('scripts')
<script>
document.getElementById('start-btn').addEventListener('click', async (e) => {
    const preset = document.getElementById('preset-select').value;
    e.target.disabled = true;
    try {
        await window.apiFetch('/api/recordings/start', { method: 'POST', body: JSON.stringify({ device_id: {{ $device->id }}, preset }) });
        window.showToast('Recording start requested.');
        setTimeout(() => location.reload(), 800);
    } catch (err) {
        window.showToast(err.message, true);
        e.target.disabled = false;
    }
});

document.getElementById('stop-btn').addEventListener('click', async (e) => {
    const recordingUuid = e.target.dataset.recording;
    if (!recordingUuid) return;
    e.target.disabled = true;
    try {
        await window.apiFetch(`/api/recordings/${recordingUuid}/stop`, { method: 'POST' });
        window.showToast('Recording stop requested.');
        setTimeout(() => location.reload(), 800);
    } catch (err) {
        window.showToast(err.message, true);
        e.target.disabled = false;
    }
});
</script>
@endpush
