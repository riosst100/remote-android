@extends('layouts.app')

@section('title', $device->name ?? $device->device_uuid)

@section('content')
<p><a href="{{ route('devices.index') }}">&larr; Devices</a></p>
<div style="display:flex;align-items:center;justify-content:space-between;gap:16px;">
    <h2 style="margin-top:0;">{{ $device->name ?? 'Unnamed device' }}</h2>
    <form method="POST" action="{{ route('devices.destroy', $device) }}"
          onsubmit="return confirm('Delete this device and ALL of its recordings? This cannot be undone.');">
        @csrf
        @method('DELETE')
        <button type="submit" class="danger">Delete Device</button>
    </form>
</div>

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

<div class="card" style="margin-bottom:24px;">
    <h3 style="margin-top:0;">Scheduled Recordings</h3>
    <p class="muted">Times are Asia/Jakarta (WIB / UTC+7). Each schedule starts a recording at the given day and time every week and stops it automatically after the set duration.</p>

    <table style="margin-bottom:16px;">
        <thead><tr><th>Day</th><th>Time</th><th>Preset</th><th>Duration</th><th>Status</th><th>On Device</th><th></th></tr></thead>
        <tbody>
        @forelse ($device->schedules as $schedule)
            <tr>
                <td>{{ $schedule->dayName() }}</td>
                <td>{{ \Illuminate\Support\Str::of($schedule->time_of_day)->substr(0, 5) }}</td>
                <td>{{ $schedule->preset->value }}</td>
                <td>{{ $schedule->duration_minutes }} min</td>
                <td>
                    <form method="POST" action="{{ route('devices.schedules.toggle', [$device, $schedule]) }}" style="display:inline;">
                        @csrf
                        <button type="submit" class="{{ $schedule->is_active ? '' : 'muted' }}" style="padding:2px 10px;font-size:12px;">
                            {{ $schedule->is_active ? 'Active' : 'Disabled' }}
                        </button>
                    </form>
                </td>
                <td>
                    @if (! $schedule->is_active)
                        <span class="muted" title="Disabled schedules aren't pulled by the device.">—</span>
                    @elseif ($schedule->isSyncedToDevice())
                        <span title="Device last pulled its schedule list at {{ $device->schedules_synced_at->toDayDateTimeString() }}.">Synced</span>
                    @else
                        <span class="muted" title="Device hasn't pulled this schedule yet — it syncs its list periodically, not instantly on change.">Pending pull…</span>
                    @endif
                </td>
                <td>
                    <form method="POST" action="{{ route('devices.schedules.destroy', [$device, $schedule]) }}" style="display:inline;" onsubmit="return confirm('Remove this schedule?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="danger" style="padding:4px 10px;font-size:12px;">Remove</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">No schedules yet.</td></tr>
        @endforelse
        </tbody>
    </table>

    <form method="POST" action="{{ route('devices.schedules.store', $device) }}" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
        @csrf
        <div>
            <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:4px;">Day</label>
            <select name="day_of_week" required>
                <option value="1">Monday</option>
                <option value="2">Tuesday</option>
                <option value="3">Wednesday</option>
                <option value="4">Thursday</option>
                <option value="5" selected>Friday</option>
                <option value="6">Saturday</option>
                <option value="0">Sunday</option>
            </select>
        </div>
        <div>
            <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:4px;">Time (WIB)</label>
            <input type="time" name="time_of_day" value="03:30" required>
        </div>
        <div>
            <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:4px;">Preset</label>
            <select name="preset" required>
                @foreach ($presets as $preset)
                    <option value="{{ $preset->value }}" {{ $preset->value === 'HIGH' ? 'selected' : '' }}>{{ $preset->value }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:4px;">Duration (minutes)</label>
            <input type="number" name="duration_minutes" value="30" min="1" max="480" required style="width:90px;">
        </div>
        <button type="submit" class="primary">Add Schedule</button>
    </form>

    @error('day_of_week') <p style="color:var(--danger);margin-top:8px;">{{ $message }}</p> @enderror
    @error('time_of_day') <p style="color:var(--danger);margin-top:8px;">{{ $message }}</p> @enderror
    @error('preset') <p style="color:var(--danger);margin-top:8px;">{{ $message }}</p> @enderror
    @error('duration_minutes') <p style="color:var(--danger);margin-top:8px;">{{ $message }}</p> @enderror
</div>

<div class="card">
    <h3 style="margin-top:0;">Recording History</h3>
    <table>
        <thead><tr><th>Recording</th><th>Status</th><th>Source</th><th>Preset</th><th>Started</th><th>Duration</th><th></th></tr></thead>
        <tbody>
        @forelse ($device->recordings as $recording)
            <tr>
                <td><code>{{ substr($recording->uuid, 0, 8) }}</code></td>
                <td><span class="badge {{ $recording->status->value }}">{{ $recording->status->value }}</span></td>
                <td>{{ $recording->source->value }}</td>
                <td>{{ $recording->preset->value }}</td>
                <td class="muted">{{ optional($recording->started_at)->diffForHumans() ?? '—' }}</td>
                <td>{{ $recording->duration ? gmdate('H:i:s', $recording->duration) : '—' }}</td>
                <td><a href="{{ route('recordings.show', $recording) }}">View</a></td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">No recordings for this device yet.</td></tr>
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
