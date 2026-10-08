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
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
        <h3 style="margin:0;">Live Sensors</h3>
        <span id="sensors-updated" class="muted" style="font-size:12px;">waiting for device…</span>
    </div>
    <p class="muted" style="margin-top:4px;">Streamed in real time while the device is running. Values are not stored — they appear as the device reports them.</p>
    <div class="grid cols-3" style="margin-top:12px;">
        <div class="card" style="background:rgba(255,255,255,.02);">
            <div class="stat-label">Light</div>
            <div class="stat" id="sensor-lux" style="font-size:28px;">—</div>
            <div class="muted" id="sensor-lux-note" style="font-size:12px;">lux</div>
        </div>
        <div class="card" style="background:rgba(255,255,255,.02);">
            <div class="stat-label">Motion</div>
            <div class="stat" id="sensor-motion" style="font-size:28px;">—</div>
            <div class="muted" id="sensor-motion-note" style="font-size:12px;">accelerometer</div>
        </div>
        <div class="card" style="background:rgba(255,255,255,.02);">
            <div class="stat-label">Proximity</div>
            <div class="stat" id="sensor-proximity" style="font-size:28px;">—</div>
            <div class="muted" id="sensor-proximity-note" style="font-size:12px;">cover sensor</div>
        </div>
    </div>

    <h4 style="margin:20px 0 8px;">Actuators</h4>
    <div class="grid cols-2" style="margin-top:4px;">
        <div class="card" style="background:rgba(255,255,255,.02);">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
                <div>
                    <div class="stat-label">Popup</div>
                    <div class="stat" id="actuator-popup" style="font-size:24px;color:var(--muted);">Unknown</div>
                </div>
                <button id="dismiss-popup-btn" {{ $device->status->value === 'OFFLINE' ? 'disabled' : '' }}>Close Popup</button>
            </div>
        </div>
        <div class="card" style="background:rgba(255,255,255,.02);">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
                <div>
                    <div class="stat-label">Flash</div>
                    <div class="stat" id="actuator-flash" style="font-size:24px;color:var(--muted);">Unknown</div>
                </div>
                <button id="flash-off-inline-btn" {{ $device->status->value === 'OFFLINE' ? 'disabled' : '' }}>Turn Off</button>
            </div>
        </div>
    </div>
</div>

<div class="card" style="margin-bottom:24px;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
        <h3 style="margin:0;">Sensor Automation</h3>
        <span id="rules-status" class="muted" style="font-size:12px;"></span>
    </div>
    <p class="muted" style="margin-top:4px;">Rules the device evaluates locally against its live sensors. All conditions in a rule must hold (AND); leave a condition blank to ignore it. The action fires once each time the conditions become true.</p>

    <div id="rules-list" style="margin-top:12px;"></div>

    <div style="display:flex;gap:8px;margin-top:12px;">
        <button id="add-rule-btn">+ Add Rule</button>
        <button id="save-rules-btn" class="primary">Save Rules</button>
    </div>

    {{-- Template for one rule row, cloned by JS. --}}
    <template id="rule-template">
        <div class="card rule-row" style="background:rgba(255,255,255,.02);margin-bottom:10px;">
            <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
                <label style="font-size:12px;color:var(--muted);">Action
                    <select class="rule-action" style="display:block;margin-top:2px;">
                        <option value="POPUP">Show Popup</option>
                        <option value="FLASH_ON">Flash On</option>
                        <option value="FLASH_OFF">Flash Off</option>
                    </select>
                </label>
                <label style="font-size:12px;color:var(--muted);">Motion
                    <select class="rule-motion" style="display:block;margin-top:2px;">
                        <option value="">(any)</option>
                        <option value="PICKED_UP">Picked up</option>
                        <option value="PUT_DOWN">Put down</option>
                        <option value="STILL">Still</option>
                        <option value="MOVING">Moving</option>
                    </select>
                </label>
                <label style="font-size:12px;color:var(--muted);">Light (lux)
                    <span style="display:flex;gap:4px;margin-top:2px;">
                        <select class="rule-lux-op">
                            <option value="">(ignore)</option>
                            <option value="lt">&lt;</option>
                            <option value="gt">&gt;</option>
                        </select>
                        <input type="number" class="rule-lux-value" min="0" step="1" placeholder="value" style="width:90px;">
                    </span>
                </label>
                <label style="font-size:12px;color:var(--muted);">Proximity
                    <select class="rule-proximity" style="display:block;margin-top:2px;">
                        <option value="">(any)</option>
                        <option value="near">Near (covered)</option>
                        <option value="far">Far (uncovered)</option>
                    </select>
                </label>
                <button class="rule-remove danger" type="button" style="margin-left:auto;">Remove</button>
            </div>
            <div class="rule-popup-fields" style="margin-top:10px;display:flex;flex-wrap:wrap;gap:10px;">
                <input type="text" class="rule-popup-title" placeholder="Popup title" maxlength="120" style="flex:1;min-width:160px;">
                <input type="text" class="rule-popup-message" placeholder="Popup message" maxlength="1000" style="flex:2;min-width:200px;">
            </div>
        </div>
    </template>
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
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
        <h3 style="margin:0;">Video Recording</h3>
        <span class="stat" id="video-status" style="font-size:20px;color:var(--muted);">Unknown</span>
    </div>
    <p class="muted" style="margin-top:4px;">Records 1080p video from the rear camera in the background (no preview, not saved to the phone's gallery). The file uploads after you stop, then appears in Recording History below to download.</p>
    @php $activeVideo = $device->recordings->first(fn($r) => !$r->status->isTerminal() && ($r->media_kind?->value ?? 'AUDIO') === 'VIDEO'); @endphp
    <button id="video-start-btn" class="primary" {{ $active || $device->status->value === 'OFFLINE' ? 'disabled' : '' }}>Start Video</button>
    <button id="video-stop-btn" class="danger" data-recording="{{ $activeVideo->uuid ?? '' }}" {{ $activeVideo ? '' : 'disabled' }}>Stop Video</button>
</div>

<div class="card" style="margin-bottom:24px;">
    <h3 style="margin-top:0;">Flashlight</h3>
    <p class="muted">Turn the device's camera flashlight (torch) on or off remotely. The device must be online.</p>
    <button id="flash-on-btn" class="primary" {{ $device->status->value === 'OFFLINE' ? 'disabled' : '' }}>Turn Flash On</button>
    <button id="flash-off-btn" {{ $device->status->value === 'OFFLINE' ? 'disabled' : '' }}>Turn Flash Off</button>
</div>

<div class="card" style="margin-bottom:24px;">
    <h3 style="margin-top:0;">Alert Popup</h3>
    <p class="muted">Show a full-screen alert with an alarm sound on the device. It keeps sounding until the user taps Dismiss on the phone. The device must be online.</p>
    @php
        $alertDefaults = $device->alert_defaults ?? [];
        $defTitle = $alertDefaults['title'] ?? 'Attention';
        $defMessage = $alertDefaults['message'] ?? 'Please check this device.';
        $defVolume = $alertDefaults['volume'] ?? 100;
        $defBrightness = $alertDefaults['brightness'] ?? 100;
        $defButtonLabel = $alertDefaults['button_label'] ?? 'Dismiss';
    @endphp
    <div style="display:flex;flex-direction:column;gap:8px;max-width:480px;">
        <input type="text" id="alert-title" placeholder="Title" value="{{ $defTitle }}" maxlength="120">
        <textarea id="alert-message" placeholder="Message" rows="3" maxlength="500">{{ $defMessage }}</textarea>
        <input type="text" id="alert-button-label" placeholder="Dismiss button label" value="{{ $defButtonLabel }}" maxlength="40">
        <div>
            <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:4px;">Volume: <span id="alert-volume-value">{{ $defVolume }}</span>%</label>
            <input type="range" id="alert-volume" min="0" max="100" value="{{ $defVolume }}" step="5" style="width:100%;max-width:320px;">
            <p class="muted" style="font-size:12px;margin:4px 0 0;">The device raises its alarm volume to this level while the alert sounds, then restores it on dismiss.</p>
        </div>
        <div>
            <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:4px;">Brightness: <span id="alert-brightness-value">{{ $defBrightness }}</span>%</label>
            <input type="range" id="alert-brightness" min="0" max="100" value="{{ $defBrightness }}" step="5" style="width:100%;max-width:320px;">
            <p class="muted" style="font-size:12px;margin:4px 0 0;">The device sets its screen brightness to this level while the alert is shown, then restores it on dismiss. Settings are remembered per device.</p>
        </div>
        <div>
            <button id="alert-btn" class="primary" {{ $device->status->value === 'OFFLINE' ? 'disabled' : '' }}>Send Alert</button>
        </div>
    </div>
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

async function setFlash(on) {
    try {
        await window.apiFetch(`/api/devices/{{ $device->id }}/flash`, { method: 'POST', body: JSON.stringify({ on }) });
        window.showToast(on ? 'Flash on requested.' : 'Flash off requested.');
    } catch (err) {
        window.showToast(err.message, true);
    }
}

document.getElementById('flash-on-btn').addEventListener('click', () => setFlash(true));
document.getElementById('flash-off-btn').addEventListener('click', () => setFlash(false));

/* Inline actuator controls on the Live Sensors card. */
document.getElementById('flash-off-inline-btn').addEventListener('click', () => setFlash(false));
document.getElementById('dismiss-popup-btn').addEventListener('click', async () => {
    try {
        await window.apiFetch(`/api/devices/{{ $device->id }}/alert/dismiss`, { method: 'POST' });
        window.showToast('Close popup requested.');
    } catch (err) {
        window.showToast(err.message, true);
    }
});

/* ---- Sensor automation rules editor ---- */
(function () {
    const existing = @json($device->sensor_rules ?? []);
    const listEl = document.getElementById('rules-list');
    const tpl = document.getElementById('rule-template');

    const syncPopupVisibility = (row) => {
        const isPopup = row.querySelector('.rule-action').value === 'POPUP';
        row.querySelector('.rule-popup-fields').style.display = isPopup ? 'flex' : 'none';
    };

    const addRow = (rule) => {
        const row = tpl.content.firstElementChild.cloneNode(true);
        if (rule) {
            row.querySelector('.rule-action').value = rule.action ?? 'POPUP';
            const w = rule.when ?? {};
            row.querySelector('.rule-motion').value = w.motion ?? '';
            row.querySelector('.rule-lux-op').value = w.lux_op ?? '';
            row.querySelector('.rule-lux-value').value = (w.lux_value ?? '') === null ? '' : (w.lux_value ?? '');
            row.querySelector('.rule-proximity').value = w.proximity ?? '';
            const p = rule.popup ?? {};
            row.querySelector('.rule-popup-title').value = p.title ?? '';
            row.querySelector('.rule-popup-message').value = p.message ?? '';
        }
        row.querySelector('.rule-action').addEventListener('change', () => syncPopupVisibility(row));
        row.querySelector('.rule-remove').addEventListener('click', () => row.remove());
        listEl.appendChild(row);
        syncPopupVisibility(row);
    };

    const collect = () => Array.from(listEl.querySelectorAll('.rule-row')).map((row) => {
        const action = row.querySelector('.rule-action').value;
        const luxOp = row.querySelector('.rule-lux-op').value || null;
        const luxVal = row.querySelector('.rule-lux-value').value;
        const rule = {
            action,
            when: {
                motion: row.querySelector('.rule-motion').value || null,
                lux_op: luxOp,
                lux_value: luxOp && luxVal !== '' ? Number(luxVal) : null,
                proximity: row.querySelector('.rule-proximity').value || null,
            },
        };
        if (action === 'POPUP') {
            rule.popup = {
                title: row.querySelector('.rule-popup-title').value.trim(),
                message: row.querySelector('.rule-popup-message').value.trim(),
                volume: 100,
                brightness: 100,
            };
        }
        return rule;
    });

    document.getElementById('add-rule-btn').addEventListener('click', () => addRow(null));
    document.getElementById('save-rules-btn').addEventListener('click', async (e) => {
        e.target.disabled = true;
        const statusEl = document.getElementById('rules-status');
        try {
            await window.apiFetch(`/api/devices/{{ $device->id }}/sensor-rules`, {
                method: 'POST',
                body: JSON.stringify({ rules: collect() }),
            });
            statusEl.textContent = 'saved — device picks it up on next heartbeat';
            window.showToast('Sensor rules saved.');
        } catch (err) {
            window.showToast(err.message, true);
        } finally {
            e.target.disabled = false;
        }
    });

    existing.forEach(addRow);
})();

const alertVolume = document.getElementById('alert-volume');
alertVolume.addEventListener('input', () => {
    document.getElementById('alert-volume-value').textContent = alertVolume.value;
});

const alertBrightness = document.getElementById('alert-brightness');
alertBrightness.addEventListener('input', () => {
    document.getElementById('alert-brightness-value').textContent = alertBrightness.value;
});

document.getElementById('alert-btn').addEventListener('click', async (e) => {
    const title = document.getElementById('alert-title').value.trim();
    const message = document.getElementById('alert-message').value.trim();
    const volume = parseInt(alertVolume.value, 10);
    const brightness = parseInt(alertBrightness.value, 10);
    const buttonLabel = document.getElementById('alert-button-label').value.trim() || 'Dismiss';
    if (!title || !message) {
        window.showToast('Title and message are required.', true);
        return;
    }
    e.target.disabled = true;
    try {
        await window.apiFetch(`/api/devices/{{ $device->id }}/alert`, { method: 'POST', body: JSON.stringify({ title, message, volume, brightness, button_label: buttonLabel }) });
        window.showToast('Alert sent.');
    } catch (err) {
        window.showToast(err.message, true);
    } finally {
        e.target.disabled = false;
    }
});

/* Live sensor telemetry, pushed over Reverb on the device's private channel. */
(function () {
    const statusEl = document.getElementById('sensors-updated');
    const setStatus = (text) => { if (statusEl) statusEl.textContent = text; };

    if (!window.realtimeReady) {
        setStatus('realtime unavailable (not logged in?)');
        console.warn('[sensors] window.realtimeReady is undefined — realtime block did not initialise.');
        return;
    }

    setStatus('connecting…');

    window.realtimeReady.then((pusher) => {
    const channel = pusher.subscribe('private-devices.{{ $device->id }}');

    channel.bind('pusher:subscription_succeeded', () => {
        setStatus('connected — waiting for readings…');
        console.info('[sensors] subscribed to private-devices.{{ $device->id }}');
    });
    channel.bind('pusher:subscription_error', (e) => {
        setStatus('subscription error (see console)');
        console.error('[sensors] subscription_error', e);
    });

    const luxNote = (lux) => {
        if (lux < 10) return 'dark';
        if (lux < 50) return 'dim';
        if (lux < 1000) return 'indoor';
        return 'bright';
    };
    const motionLabel = {
        STILL: 'Still', PICKED_UP: 'Picked up', PUT_DOWN: 'Put down', MOVING: 'Moving',
    };

    channel.bind('DeviceSensorsUpdated', (payload) => {
        console.debug('[sensors] event', payload);
        const s = payload.sensors || {};

        if (s.lux !== undefined) {
            document.getElementById('sensor-lux').textContent = Math.round(s.lux);
            document.getElementById('sensor-lux-note').textContent = `lux · ${luxNote(s.lux)}`;
        }
        if (s.motion !== undefined) {
            document.getElementById('sensor-motion').textContent = motionLabel[s.motion] ?? s.motion;
            const mag = s.accel_magnitude;
            document.getElementById('sensor-motion-note').textContent =
                mag !== undefined ? `${mag.toFixed(1)} m/s²` : 'accelerometer';
        }
        if (s.proximity_near !== undefined) {
            document.getElementById('sensor-proximity').textContent = s.proximity_near ? 'Near' : 'Far';
            document.getElementById('sensor-proximity-note').textContent =
                s.proximity_near ? 'covered / in pocket' : 'uncovered';
        }
        if (s.popup_shown !== undefined) {
            const el = document.getElementById('actuator-popup');
            el.textContent = s.popup_shown ? 'Showing' : 'None';
            el.style.color = s.popup_shown ? 'var(--accent)' : 'var(--muted)';
        }
        if (s.flash_on !== undefined) {
            const el = document.getElementById('actuator-flash');
            el.textContent = s.flash_on ? 'On' : 'Off';
            el.style.color = s.flash_on ? 'var(--warn)' : 'var(--muted)';
        }

        const when = payload.at ? new Date(payload.at).toLocaleTimeString() : new Date().toLocaleTimeString();
        document.getElementById('sensors-updated').textContent = `updated ${when}`;
    });
    });
})();
</script>
@endpush
