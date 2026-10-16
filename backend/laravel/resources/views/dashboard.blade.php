@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:24px;margin-bottom:32px;">
    <div>
        <h1 style="margin-bottom:8px;">Fleet Overview</h1>
        <p class="muted" style="margin:0;">Real-time status of your connected Android devices.</p>
    </div>
    <a href="{{ route('devices.index') }}" class="btn primary">Manage Fleet <span aria-hidden="true" style="margin-left:4px;">&rarr;</span></a>
</div>

<div class="grid cols-5" style="margin-bottom:32px;">
    <div class="card" style="border-left: 4px solid var(--border);">
        <div class="stat-value">{{ $stats['total_devices'] }}</div>
        <div class="stat-label">Total Devices</div>
        <div class="muted" style="font-size:12px;margin-top:8px;">Registered devices</div>
    </div>
    <div class="card" style="border-left: 4px solid var(--ok);">
        <div class="stat-value" style="color:var(--ok);">{{ $stats['online_devices'] }}</div>
        <div class="stat-label">Online Now</div>
        <div class="muted" style="font-size:12px;margin-top:8px;">Ready to receive commands</div>
    </div>
    <div class="card" style="border-left: 4px solid var(--accent);">
        <div class="stat-value" style="color:var(--accent);">{{ $stats['recording_devices'] }}</div>
        <div class="stat-label">Active Recording</div>
        <div class="muted" style="font-size:12px;margin-top:8px;">Currently capturing audio/video</div>
    </div>
    <div class="card" style="border-left: 4px solid var(--muted);">
        <div class="stat-value" style="color:var(--muted);">{{ $stats['offline_devices'] }}</div>
        <div class="stat-label">Offline</div>
        <div class="muted" style="font-size:12px;margin-top:8px;">Disconnected devices</div>
    </div>
    <div class="card" style="border-left: 4px solid var(--danger);">
        <div class="stat-value" style="color:var(--danger);">{{ $stats['error_devices'] }}</div>
        <div class="stat-label">Action Required</div>
        <div class="muted" style="font-size:12px;margin-top:8px;">Devices with failure status</div>
    </div>
</div>

<div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
        <h3 style="margin:0;">Recent Activity</h3>
        <a href="{{ route('recordings.index') }}" class="muted" style="font-size:13px;font-weight:600;">View All Activity &rarr;</a>
    </div>
    <table>
        <thead>
            <tr><th>Session ID</th><th>Device</th><th>Status</th><th>Preset</th><th>Started</th><th style="text-align:right;">Action</th></tr>
        </thead>
        <tbody>
            @forelse ($recentRecordings as $recording)
            <tr>
                <td><code>{{ substr($recording->uuid, 0, 8) }}</code></td>
                <td style="font-weight:600;">{{ $recording->device->name ?? $recording->device->device_uuid }}</td>
                <td><span class="badge {{ $recording->status->value }}">{{ $recording->status->value }}</span></td>
                <td><span class="muted" style="font-size:12px;font-weight:600;">{{ $recording->preset->value }}</span></td>
                <td class="muted">{{ optional($recording->started_at)->diffForHumans() ?? '—' }}</td>
                <td style="text-align:right;"><a href="{{ route('recordings.show', $recording) }}" class="btn" style="padding:6px 12px;font-size:12px;">Review</a></td>
            </tr>
            @empty
            <tr><td colspan="6" class="muted" style="text-align:center;padding:48px 0;">No recording activity found in the last 24 hours.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
