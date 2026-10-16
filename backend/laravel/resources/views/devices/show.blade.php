@extends('layouts.app')

@section('title', $device->name ?? $device->device_uuid)

@section('content')
<div style="margin-bottom:24px;">
    <a href="{{ route('devices.index') }}" class="muted" style="font-size:13px;display:flex;align-items:center;gap:6px;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
        Back to Fleet
    </a>
</div>

<div style="display:flex;align-items:center;justify-content:space-between;gap:24px;margin-bottom:32px;">
    <div style="display:flex;align-items:center;gap:16px;">
        <div style="width:48px;height:48px;background:var(--panel-2);border:1px solid var(--border);border-radius:12px;display:flex;align-items:center;justify-content:center;color:var(--accent);">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><path d="M12 18h.01"/></svg>
        </div>
        <div>
            <h1 style="font-size:28px;">{{ $device->name ?? 'Unnamed Device' }}</h1>
            <div style="display:flex;align-items:center;gap:12px;margin-top:4px;">
                <span class="badge {{ $device->status->value }}">{{ $device->status->value }}</span>
                <span class="muted" style="font-size:13px;">Last seen {{ optional($device->last_seen_at)->diffForHumans() ?? 'never' }}</span>
            </div>
        </div>
    </div>
    <form method="POST" action="{{ route('devices.destroy', $device) }}" onsubmit="return confirm('Delete this device and ALL of its recordings? This cannot be undone.');">
        @csrf
        @method('DELETE')
        <button type="submit" class="danger">Delete Device</button>
    </form>
</div>

{{-- Tabs Navigation --}}
<div style="display:flex;gap:32px;border-bottom:1px solid var(--border);margin-bottom:32px;">
    <button class="tab-link active" onclick="openTab(event, 'tab-monitor')">Monitor</button>
    <button class="tab-link" onclick="openTab(event, 'tab-control')">Remote Control</button>
    <button class="tab-link" onclick="openTab(event, 'tab-automation')">Automation</button>
    <button class="tab-link" onclick="openTab(event, 'tab-history')">History</button>
</div>

<style>
    .tab-link { 
        background: none; border: none; padding: 0 0 12px 0; color: var(--muted); font-weight: 600; 
        font-size: 15px; border-bottom: 2px solid transparent; border-radius: 0; cursor: pointer;
    }
    .tab-link:hover { color: var(--text); }
    .tab-link.active { color: var(--accent); border-bottom-color: var(--accent); }
    .tab-content { display: none; animation: fadeIn 0.3s ease-out; }
    .tab-content.active { display: block; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
    
    .sensor-card { 
        background: linear-gradient(135deg, var(--panel), rgba(255,255,255,0.02)); 
        border: 1px solid var(--border); border-radius: 16px; padding: 20px;
    }
    .sensor-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); margin-bottom: 12px; }
    .sensor-val { font-size: 32px; font-weight: 800; color: var(--text); font-family: 'JetBrains Mono', monospace; }
</style>

<div id="tab-monitor" class="tab-content active">
    <div style="text-align:right;margin-bottom:8px;"><span id="sensors-updated" class="muted" style="font-size:12px;">waiting for device…</span></div>
    <div class="grid cols-3" style="margin-bottom:24px;">
        <div class="sensor-card">
            <div class="sensor-label">Light Level</div>
            <div class="sensor-val" id="sensor-lux">—</div>
            <div class="muted" id="sensor-lux-note" style="font-size:12px;margin-top:4px;">lux</div>
        </div>
        <div class="sensor-card">
            <div class="sensor-label">Motion State</div>
            <div class="sensor-val" id="sensor-motion">—</div>
            <div class="muted" id="sensor-motion-note" style="font-size:12px;margin-top:4px;">accelerometer</div>
        </div>
        <div class="sensor-card">
            <div class="sensor-label">Proximity</div>
            <div class="sensor-val" id="sensor-proximity">—</div>
            <div class="muted" id="sensor-proximity-note" style="font-size:12px;margin-top:4px;">cover sensor</div>
        </div>
    </div>

    <div class="grid cols-2" style="margin-bottom:32px;">
        <div class="card">
            <h3 style="margin-bottom:20px;">Device Information</h3>
            <table class="muted" style="font-size:13px;">
                <tr><th style="width:140px;">UUID</th><td><code>{{ $device->device_uuid }}</code></td></tr>
                <tr><th>Manufacturer</th><td style="color:var(--text);font-weight:600;">{{ $device->manufacturer ?? '—' }}</td></tr>
                <tr><th>Model</th><td style="color:var(--text);font-weight:600;">{{ $device->model ?? '—' }}</td></tr>
                <tr><th>Android Version</th><td><span class="badge">{{ $device->android_version ?? '—' }}</span></td></tr>
                <tr><th>App Version</th><td>{{ $device->app_version ?? '—' }}</td></tr>
                <tr><th>Status</th><td><span class="badge {{ $device->status->value }}">{{ $device->status->value }}</span></td></tr>
                <tr><th>Last Heartbeat</th><td>{{ optional($device->last_seen_at)->toDayDateTimeString() }}</td></tr>
            </table>
        </div>
        <div class="card">
            <h3 style="margin-bottom:20px;">Hardware Capabilities</h3>
            @if ($device->capabilities)
            <table class="muted" style="font-size:13px;">
                <tr><th style="width:140px;">Encoders</th><td style="color:var(--text);">{{ implode(', ', $device->capabilities['encoders'] ?? []) }}</td></tr>
                <tr><th>Sample Rates</th><td style="color:var(--text);">{{ implode(', ', $device->capabilities['sample_rates'] ?? []) }}</td></tr>
                <tr><th>Channels</th><td style="color:var(--text);">{{ implode(', ', $device->capabilities['channels'] ?? []) }}</td></tr>
                <tr><th>Bitrates</th><td style="color:var(--text);">{{ implode(', ', (array) ($device->capabilities['bitrates'] ?? [])) }}</td></tr>
            </table>
            @else
            <div style="text-align:center;padding:32px 0;">
                <p class="muted">No hardware capability data reported yet.</p>
            </div>
            @endif
        </div>
    </div>
</div>

<div id="tab-control" class="tab-content">
    <div class="grid cols-2" style="margin-bottom:32px;">
        <div class="card">
            <h3 style="margin-bottom:8px;">Audio Recording</h3>
            <p class="muted" style="margin-bottom:20px;font-size:13px;">Configure and trigger high-quality audio capture.</p>
            
            @php $active = $device->recordings->first(fn($r) => !$r->status->isTerminal()); @endphp
            <div style="display:flex;flex-direction:column;gap:16px;">
                <div style="display:flex;gap:12px;">
                    <select id="preset-select" {{ $active ? 'disabled' : '' }} style="flex:1;">
                        @foreach ($presets as $preset)
                            <option value="{{ $preset->value }}" {{ $preset->value === 'HIGH' ? 'selected' : '' }}>{{ $preset->value }}</option>
                        @endforeach
                    </select>
                    @if (!$active)
                        <button id="start-btn" class="primary" {{ $device->status->value === 'OFFLINE' ? 'disabled' : '' }}>Start Session</button>
                    @else
                        <button id="stop-btn" class="danger" data-recording="{{ $active->uuid ?? '' }}">Stop Session</button>
                    @endif
                </div>
                
                <table style="font-size:12px;background:rgba(255,255,255,0.02);border-radius:8px;">
                    <thead><tr><th>Preset</th><th>Enc</th><th>Rate</th><th>Bits</th></tr></thead>
                    <tbody>
                    @foreach ($previewConfigs as $preset => $config)
                        <tr><td>{{ $preset }}</td><td>{{ $config['encoder'] }}</td><td>{{ $config['sample_rate'] }}Hz</td><td>{{ $config['bitrate'] ? number_format($config['bitrate'] / 1000).'k' : 'lossless' }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <h3 style="margin-bottom:8px;">Video Recording</h3>
            <p class="muted" style="margin-bottom:20px;font-size:13px;">Captures 1080p background video from the rear camera.</p>
            
            @php $activeVideo = $device->recordings->first(fn($r) => !$r->status->isTerminal() && ($r->media_kind?->value ?? 'AUDIO') === 'VIDEO'); @endphp
            <div style="display:flex;flex-direction:column;gap:16px;">
                <div style="display:flex;align-items:center;justify-content:space-between;">
                    <span id="video-status" class="badge" style="font-size:12px;">Idle</span>
                    <div style="display:flex;gap:12px;">
                        @if (!$activeVideo)
                            <button id="video-start-btn" class="primary" {{ $device->status->value === 'OFFLINE' ? 'disabled' : '' }}>Start Video</button>
                        @else
                            <button id="video-stop-btn" class="danger" data-recording="{{ $activeVideo->uuid ?? '' }}">Stop Video</button>
                        @endif
                    </div>
                </div>
                
                <div style="padding-top:16px;border-top:1px solid var(--border);">
                    <label class="stat-label" style="display:block;margin-bottom:8px;">Auto-stop after (minutes)</label>
                    <div style="display:flex;align-items:center;gap:12px;">
                        <input type="number" id="video-max-minutes" min="0" max="1440" step="1" value="{{ $device->max_video_minutes ?? 0 }}" style="width:100px;">
                        <span class="muted" style="font-size:12px;">0 = continuous recording until manually stopped.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="grid cols-2">
        <div class="card">
            <h3 style="margin-bottom:8px;">Alert & Alarms</h3>
            <p class="muted" style="margin-bottom:20px;font-size:13px;">Trigger a full-screen alert with a siren sound.</p>
            
            @php
                $alertDefaults = $device->alert_defaults ?? [];
                $defTitle = $alertDefaults['title'] ?? 'Attention';
                $defMessage = $alertDefaults['message'] ?? 'Please check this device.';
                $defVolume = $alertDefaults['volume'] ?? 100;
                $defBrightness = $alertDefaults['brightness'] ?? 100;
                $defButtonLabel = $alertDefaults['button_label'] ?? 'Dismiss';
            @endphp
            
            <div style="display:flex;flex-direction:column;gap:12px;">
                <input type="text" id="alert-title" placeholder="Alert Title" value="{{ $defTitle }}" maxlength="120">
                <textarea id="alert-message" placeholder="Message content..." rows="2" maxlength="500">{{ $defMessage }}</textarea>
                <div class="grid cols-2">
                    <div>
                        <label class="stat-label">Volume: <span id="alert-volume-value">{{ $defVolume }}</span>%</label>
                        <input type="range" id="alert-volume" min="0" max="100" value="{{ $defVolume }}" step="5">
                    </div>
                    <div>
                        <label class="stat-label">Brightness: <span id="alert-brightness-value">{{ $defBrightness }}</span>%</label>
                        <input type="range" id="alert-brightness" min="0" max="100" value="{{ $defBrightness }}" step="5">
                    </div>
                </div>
                <div style="display:flex;gap:12px;margin-top:8px;">
                    <input type="text" id="alert-button-label" placeholder="Button Label" value="{{ $defButtonLabel }}" maxlength="40" style="flex:1;">
                    <button id="alert-btn" class="primary" {{ $device->status->value === 'OFFLINE' ? 'disabled' : '' }} style="flex:1;">Send Alert</button>
                </div>
                <div style="display:flex;justify-content:flex-end;">
                    <button id="dismiss-popup-btn" class="btn-sm" style="font-size:11px;">Force Close Remote Popup</button>
                </div>
            </div>
        </div>

        <div class="card">
            <h3 style="margin-bottom:8px;">Device Controls</h3>
            <p class="muted" style="margin-bottom:24px;font-size:13px;">Direct hardware actuator commands.</p>
            
            <div class="grid cols-1" style="gap:16px;">
                <div style="display:flex;align-items:center;justify-content:space-between;padding:16px;background:rgba(255,255,255,0.02);border-radius:12px;">
                    <div>
                        <div class="stat-label">Flashlight</div>
                        <div id="actuator-flash" style="font-weight:700;font-size:18px;">Off</div>
                    </div>
                    <div style="display:flex;gap:8px;">
                        <button id="flash-on-btn" class="btn-sm primary" {{ $device->status->value === 'OFFLINE' ? 'disabled' : '' }}>On</button>
                        <button id="flash-off-btn" class="btn-sm" {{ $device->status->value === 'OFFLINE' ? 'disabled' : '' }}>Off</button>
                    </div>
                </div>
                
                <div style="display:flex;align-items:center;justify-content:space-between;padding:16px;background:rgba(255,255,255,0.02);border-radius:12px;">
                    <div>
                        <div class="stat-label">Active Popup</div>
                        <div id="actuator-popup" style="font-weight:700;font-size:18px;">None</div>
                    </div>
                    <span class="muted" style="font-size:12px;">Status from device</span>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="tab-automation" class="tab-content">
    <div class="card" style="margin-bottom:32px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
            <h3 style="margin:0;">Sensor Automation Rules</h3>
            <span id="rules-status" class="muted" style="font-size:12px;"></span>
        </div>
        <p class="muted" style="margin-bottom:24px;font-size:13px;">Automate actions based on environmental sensor triggers.</p>

        <div id="rules-list"></div>

        <div style="display:flex;gap:12px;margin-top:20px;padding-top:20px;border-top:1px solid var(--border);">
            <button id="add-rule-btn" class="btn">+ Add New Rule</button>
            <button id="save-rules-btn" class="primary">Save Changes</button>
        </div>

        <template id="rule-template">
            <div class="card rule-row" style="background:rgba(255,255,255,0.02);margin-bottom:12px;padding:20px;">
                <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end;">
                    <div style="flex:1;min-width:140px;">
                        <label class="stat-label" style="display:block;margin-bottom:6px;">Action</label>
                        <select class="rule-action">
                            <option value="POPUP">Show Popup</option>
                            <option value="FLASH_ON">Flash On</option>
                            <option value="FLASH_OFF">Flash Off</option>
                            <option value="VIDEO_START">Start Video</option>
                            <option value="VIDEO_STOP">Stop Video</option>
                        </select>
                    </div>
                    <div style="flex:1;min-width:140px;">
                        <label class="stat-label" style="display:block;margin-bottom:6px;">Motion</label>
                        <select class="rule-motion">
                            <option value="">(any)</option>
                            <option value="PICKED_UP">Picked up</option>
                            <option value="PUT_DOWN">Put down</option>
                            <option value="STILL">Still</option>
                            <option value="MOVING">Moving</option>
                        </select>
                    </div>
                    <div style="flex:1.5;min-width:180px;">
                        <label class="stat-label" style="display:block;margin-bottom:6px;">Light Level (lux)</label>
                        <div style="display:flex;gap:8px;">
                            <select class="rule-lux-op" style="width:70px;">
                                <option value="">(any)</option>
                                <option value="lt">&lt;</option>
                                <option value="gt">&gt;</option>
                            </select>
                            <input type="number" class="rule-lux-value" placeholder="val" style="flex:1;">
                        </div>
                    </div>
                    <div style="flex:1;min-width:140px;">
                        <label class="stat-label" style="display:block;margin-bottom:6px;">Proximity</label>
                        <select class="rule-proximity">
                            <option value="">(any)</option>
                            <option value="near">Near (covered)</option>
                            <option value="far">Far (open)</option>
                        </select>
                    </div>
                    <div style="width:100px;">
                        <label class="stat-label" style="display:block;margin-bottom:6px;">Delay (s)</label>
                        <input type="number" class="rule-for-seconds" min="0" placeholder="0">
                    </div>
                    <button class="rule-remove danger" style="padding:10px;">Remove</button>
                </div>
                
                <div class="rule-popup-fields" style="margin-top:16px;display:flex;gap:12px;">
                    <input type="text" class="rule-popup-title" placeholder="Popup Title" style="flex:1;">
                    <input type="text" class="rule-popup-message" placeholder="Popup Message" style="flex:2;">
                </div>
                
                <div class="rule-videoflash-fields" style="margin-top:16px;">
                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;">
                        <input type="checkbox" class="rule-with-flash"> Also turn on flash when recording starts
                    </label>
                </div>
                
                <div class="rule-schedule-fields" style="margin-top:16px;display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end;padding-top:16px;border-top:1px dashed var(--border);">
                    <div>
                        <label class="stat-label" style="display:block;margin-bottom:8px;">Active Days</label>
                        <div style="display:flex;gap:12px;flex-wrap:wrap;">
                            @foreach (['S' => 0, 'M' => 1, 'T' => 2, 'W' => 3, 'T' => 4, 'F' => 5, 'S' => 6] as $lbl => $d)
                            <label style="display:flex;align-items:center;gap:4px;font-size:12px;">
                                <input type="checkbox" class="rule-day" value="{{ $d }}"> {{ $lbl }}
                            </label>
                            @endforeach
                        </div>
                    </div>
                    <div>
                        <label class="stat-label" style="display:block;margin-bottom:6px;">Time Window</label>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <input type="time" class="rule-time-from" style="width:120px;">
                            <span class="muted">&rarr;</span>
                            <input type="time" class="rule-time-to" style="width:120px;">
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>

    <div class="card">
        <h3 style="margin-bottom:20px;">Scheduled Tasks</h3>
        <table style="margin-bottom:24px;">
            <thead><tr><th>Day</th><th>Time (WIB)</th><th>Preset</th><th>Duration</th><th>Status</th><th style="text-align:right;">Actions</th></tr></thead>
            <tbody>
            @forelse ($device->schedules as $schedule)
                <tr>
                    <td style="font-weight:600;">{{ $schedule->dayName() }}</td>
                    <td><code>{{ \Illuminate\Support\Str::of($schedule->time_of_day)->substr(0, 5) }}</code></td>
                    <td><span class="badge">{{ $schedule->preset->value }}</span></td>
                    <td>{{ $schedule->duration_minutes }} min</td>
                    <td>
                        <form method="POST" action="{{ route('devices.schedules.toggle', [$device, $schedule]) }}">
                            @csrf
                            <button type="submit" class="btn-sm {{ $schedule->is_active ? '' : 'muted' }}" style="padding:4px 12px;font-size:11px;">
                                {{ $schedule->is_active ? '● Active' : '○ Disabled' }}
                            </button>
                        </form>
                    </td>
                    <td style="text-align:right;">
                        <form method="POST" action="{{ route('devices.schedules.destroy', [$device, $schedule]) }}" onsubmit="return confirm('Remove schedule?');">
                            @csrf @method('DELETE')
                            <button type="submit" class="danger btn-sm">Remove</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted" style="text-align:center;padding:32px 0;">No active schedules configured.</td></tr>
            @endforelse
            </tbody>
        </table>

        <form method="POST" action="{{ route('devices.schedules.store', $device) }}" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;background:rgba(255,255,255,0.02);padding:20px;border-radius:12px;">
            @csrf
            <div style="flex:1;min-width:120px;">
                <label class="stat-label" style="display:block;margin-bottom:6px;">Repeat Day</label>
                <select name="day_of_week" required>
                    <option value="1">Monday</option><option value="2">Tuesday</option><option value="3">Wednesday</option><option value="4">Thursday</option><option value="5" selected>Friday</option><option value="6">Saturday</option><option value="0">Sunday</option>
                </select>
            </div>
            <div style="width:120px;">
                <label class="stat-label" style="display:block;margin-bottom:6px;">Start Time</label>
                <input type="time" name="time_of_day" value="03:30" required>
            </div>
            <div style="width:100px;">
                <label class="stat-label" style="display:block;margin-bottom:6px;">Preset</label>
                <select name="preset" required>
                    @foreach ($presets as $preset)
                        <option value="{{ $preset->value }}" {{ $preset->value === 'HIGH' ? 'selected' : '' }}>{{ $preset->value }}</option>
                    @endforeach
                </select>
            </div>
            <div style="width:100px;">
                <label class="stat-label" style="display:block;margin-bottom:6px;">Min</label>
                <input type="number" name="duration_minutes" value="30" min="1" max="480" required>
            </div>
            <button type="submit" class="primary" style="height:42px;">Add Schedule</button>
        </form>
    </div>
</div>

<div id="tab-history" class="tab-content">
    <div class="card" style="padding:0;">
        <table>
            <thead><tr><th>Session</th><th>Media</th><th>Status</th><th>Source</th><th>Started</th><th>Duration</th><th style="text-align:right;">Actions</th></tr></thead>
            <tbody>
            @forelse ($device->recordings as $recording)
                @php $kind = $recording->media_kind?->value ?? 'AUDIO'; @endphp
                <tr>
                    <td><code>{{ substr($recording->uuid, 0, 8) }}</code></td>
                    <td>
                        <span style="display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;">
                            {!! $kind === 'VIDEO' ? '🎥 <span class="muted">Video</span>' : '🎙 <span class="muted">Audio</span>' !!}
                        </span>
                    </td>
                    <td><span class="badge {{ $recording->status->value }}">{{ $recording->status->value }}</span></td>
                    <td class="muted" style="font-size:12px;">{{ $recording->source->value }}</td>
                    <td class="muted">{{ optional($recording->started_at)->diffForHumans() ?? '—' }}</td>
                    <td style="font-family:monospace;font-size:13px;">{{ $recording->duration ? gmdate('H:i:s', $recording->duration) : '—' }}</td>
                    <td style="text-align:right;">
                        <div style="display:inline-flex;gap:8px;">
                            <a href="{{ route('recordings.show', $recording) }}" class="btn-sm" style="padding:6px 12px;">Details</a>
                            @if ($recording->status->value === 'COMPLETED')
                                <a href="{{ route('recordings.download', $recording) }}" class="btn-sm primary" style="padding:6px 12px;">Download</a>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted" style="text-align:center;padding:64px 0;">No recording history for this device.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<script>
function openTab(evt, tabName) {
    var i, tabcontent, tablinks;
    tabcontent = document.getElementsByClassName("tab-content");
    for (i = 0; i < tabcontent.length; i++) { tabcontent[i].classList.remove("active"); }
    tablinks = document.getElementsByClassName("tab-link");
    for (i = 0; i < tablinks.length; i++) { tablinks[i].classList.remove("active"); }
    document.getElementById(tabName).classList.add("active");
    evt.currentTarget.classList.add("active");
    localStorage.setItem('activeDeviceTab', tabName);
}

// Restore active tab
document.addEventListener('DOMContentLoaded', () => {
    const savedTab = localStorage.getItem('activeDeviceTab');
    if (savedTab && document.getElementById(savedTab)) {
        const btn = Array.from(document.querySelectorAll('.tab-link')).find(b => b.getAttribute('onclick').includes(savedTab));
        if (btn) btn.click();
    }
});
</script>
@endsection

@push('scripts')
<script>
document.getElementById('start-btn')?.addEventListener('click', async (e) => {
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

document.getElementById('stop-btn')?.addEventListener('click', async (e) => {
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

/* Video recording start/stop. */
document.getElementById('video-start-btn')?.addEventListener('click', async (e) => {
    e.target.disabled = true;
    try {
        await window.apiFetch('/api/recordings/video/start', { method: 'POST', body: JSON.stringify({ device_id: {{ $device->id }} }) });
        window.showToast('Video recording started.');
        setTimeout(() => location.reload(), 800);
    } catch (err) {
        window.showToast(err.message, true);
        e.target.disabled = false;
    }
});

document.getElementById('video-stop-btn')?.addEventListener('click', async (e) => {
    const recordingUuid = e.target.dataset.recording;
    if (!recordingUuid) return;
    e.target.disabled = true;
    try {
        await window.apiFetch(`/api/recordings/${recordingUuid}/video/stop`, { method: 'POST' });
        window.showToast('Video stop requested — uploading & merging.');
        setTimeout(() => location.reload(), 1200);
    } catch (err) {
        window.showToast(err.message, true);
        e.target.disabled = false;
    }
});

/* Save the max video duration as soon as it's changed. */
document.getElementById('video-max-minutes')?.addEventListener('change', async (e) => {
    const minutes = parseInt(e.target.value, 10);
    try {
        await window.apiFetch(`/api/devices/{{ $device->id }}/video/settings`, {
            method: 'POST',
            body: JSON.stringify({ max_video_minutes: Number.isFinite(minutes) ? minutes : 0 }),
        });
        window.showToast(minutes > 0 ? `Video auto-stops after ${minutes} min.` : 'Video duration limit removed.');
    } catch (err) {
        window.showToast(err.message, true);
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

document.getElementById('flash-on-btn')?.addEventListener('click', () => setFlash(true));
document.getElementById('flash-off-btn')?.addEventListener('click', () => setFlash(false));

/* Inline actuator controls on the Live Sensors card. */
document.getElementById('dismiss-popup-btn')?.addEventListener('click', async () => {
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
        const action = row.querySelector('.rule-action').value;
        row.querySelector('.rule-popup-fields').style.display = action === 'POPUP' ? 'flex' : 'none';
        row.querySelector('.rule-videoflash-fields').style.display = action === 'VIDEO_START' ? 'block' : 'none';
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
            row.querySelector('.rule-for-seconds').value = (w.for_seconds ?? '') === null ? '' : (w.for_seconds ?? '');
            (w.days ?? []).forEach((d) => {
                const cb = row.querySelector(`.rule-day[value="${d}"]`);
                if (cb) cb.checked = true;
            });
            row.querySelector('.rule-time-from').value = w.time_from ?? '';
            row.querySelector('.rule-time-to').value = w.time_to ?? '';
            const p = rule.popup ?? {};
            row.querySelector('.rule-popup-title').value = p.title ?? '';
            row.querySelector('.rule-popup-message').value = p.message ?? '';
            row.querySelector('.rule-with-flash').checked = !!rule.with_flash;
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
        const days = Array.from(row.querySelectorAll('.rule-day:checked')).map((c) => Number(c.value));
        const rule = {
            action,
            when: {
                motion: row.querySelector('.rule-motion').value || null,
                lux_op: luxOp,
                lux_value: luxOp && luxVal !== '' ? Number(luxVal) : null,
                proximity: row.querySelector('.rule-proximity').value || null,
                for_seconds: row.querySelector('.rule-for-seconds').value !== '' ? Number(row.querySelector('.rule-for-seconds').value) : null,
                days: days.length ? days : null,
                time_from: row.querySelector('.rule-time-from').value || null,
                time_to: row.querySelector('.rule-time-to').value || null,
            },
        };
        if (action === 'POPUP') {
            rule.popup = {
                title: row.querySelector('.rule-popup-title').value.trim(),
                message: row.querySelector('.rule-popup-message').value.trim(),
            };
        }
        if (action === 'VIDEO_START') {
            rule.with_flash = row.querySelector('.rule-with-flash').checked;
        }
        return rule;
    });

    document.getElementById('add-rule-btn')?.addEventListener('click', () => addRow(null));
    document.getElementById('save-rules-btn')?.addEventListener('click', async (e) => {
        e.target.disabled = true;
        const statusEl = document.getElementById('rules-status');
        try {
            await window.apiFetch(`/api/devices/{{ $device->id }}/sensor-rules`, {
                method: 'POST',
                body: JSON.stringify({ rules: collect() }),
            });
            statusEl.textContent = 'saved — applies from the next sensor reading';
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
alertVolume?.addEventListener('input', () => {
    document.getElementById('alert-volume-value').textContent = alertVolume.value;
});

const alertBrightness = document.getElementById('alert-brightness');
alertBrightness?.addEventListener('input', () => {
    document.getElementById('alert-brightness-value').textContent = alertBrightness.value;
});

/* Save the sliders as soon as they're released, so sensor-rule popups pick
   up the new volume/brightness without the admin sending a manual alert. */
async function saveAlertLevels() {
    try {
        await window.apiFetch(`/api/devices/{{ $device->id }}/alert/defaults`, {
            method: 'POST',
            body: JSON.stringify({ volume: parseInt(alertVolume.value, 10), brightness: parseInt(alertBrightness.value, 10) }),
        });
        window.showToast('Alert volume & brightness saved.');
    } catch (err) {
        window.showToast(err.message, true);
    }
}
alertVolume?.addEventListener('change', saveAlertLevels);
alertBrightness?.addEventListener('change', saveAlertLevels);

document.getElementById('alert-btn')?.addEventListener('click', async (e) => {
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
        if (s.video_recording !== undefined) {
            const el = document.getElementById('video-status');
            if (el) {
                el.textContent = s.video_recording ? 'Recording' : 'Idle';
                el.style.color = s.video_recording ? 'var(--danger)' : 'var(--muted)';
            }
        }

        const when = payload.at ? new Date(payload.at).toLocaleTimeString() : new Date().toLocaleTimeString();
        setStatus(`updated ${when}`);
    });
    });
})();
</script>
@endpush
