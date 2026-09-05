@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
<div class="grid cols-4" style="margin-bottom:24px;">
    <div class="card">
        <div class="stat">{{ $stats['total_devices'] }}</div>
        <div class="stat-label">Devices</div>
    </div>
    <div class="card">
        <div class="stat" style="color:var(--ok);">{{ $stats['online_devices'] }}</div>
        <div class="stat-label">Online</div>
    </div>
    <div class="card">
        <div class="stat" style="color:var(--accent);">{{ $stats['recording_devices'] }}</div>
        <div class="stat-label">Recording</div>
    </div>
    <div class="card">
        <div class="stat" style="color:var(--muted);">{{ $stats['offline_devices'] }}</div>
        <div class="stat-label">Offline</div>
    </div>
</div>

<div class="card" style="margin-bottom:24px;">
    <h3 style="margin-top:0;">Recent Recordings</h3>
    <table>
        <thead>
            <tr><th>Recording</th><th>Device</th><th>Status</th><th>Preset</th><th>Started</th><th></th></tr>
        </thead>
        <tbody>
            @forelse ($recentRecordings as $recording)
            <tr>
                <td><code>{{ substr($recording->uuid, 0, 8) }}</code></td>
                <td>{{ $recording->device->name ?? $recording->device->device_uuid }}</td>
                <td><span class="badge {{ $recording->status->value }}">{{ $recording->status->value }}</span></td>
                <td>{{ $recording->preset->value }}</td>
                <td class="muted">{{ optional($recording->started_at)->diffForHumans() ?? '—' }}</td>
                <td><a href="{{ route('recordings.show', $recording) }}">View</a></td>
            </tr>
            @empty
            <tr><td colspan="6" class="muted">No recordings yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<a href="{{ route('devices.index') }}" class="btn">Manage Devices &rarr;</a>
@endsection
