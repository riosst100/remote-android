@extends('layouts.app')

@section('title', 'Recordings')

@section('content')
<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:24px;margin-bottom:32px;">
    <div>
        <h1 style="margin-bottom:8px;">Recording Sessions</h1>
        <p class="muted" style="margin:0;">Browse and audit captured audio and video recordings.</p>
    </div>
</div>

<div class="card" style="margin-bottom:24px;padding:16px 20px;">
    <form method="GET" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
        <div style="display:flex;align-items:center;gap:10px;">
            <label for="recording-status-filter" class="muted" style="font-size:13px;font-weight:600;">Status:</label>
            <select id="recording-status-filter" name="status" onchange="this.form.submit()" style="width:180px;padding:6px 12px;font-size:13px;">
                <option value="">All Statuses</option>
                @foreach (['PENDING','STARTING','RECORDING','STOPPING','PROCESSING','COMPLETED','FAILED'] as $status)
                    <option value="{{ $status }}" {{ request('status') === $status ? 'selected' : '' }}>{{ $status }}</option>
                @endforeach
            </select>
        </div>
        @if(request('status'))
            <a href="{{ route('recordings.index') }}" class="muted" style="font-size:12px;">Clear filter</a>
        @endif
    </form>
</div>

<div class="card" style="padding:0;">
    <table>
        <thead>
            <tr><th>Session</th><th>Device</th><th>Status</th><th>Type</th><th>Chunks</th><th>Duration</th><th>Started</th><th style="text-align:right;">Actions</th></tr>
        </thead>
        <tbody>
        @forelse ($recordings as $recording)
            @php $kind = $recording->media_kind?->value ?? 'AUDIO'; @endphp
            <tr>
                <td><code>{{ substr($recording->uuid, 0, 8) }}</code></td>
                <td style="font-weight:600;">{{ $recording->device->name ?? $recording->device->device_uuid }}</td>
                <td><span class="badge {{ $recording->status->value }}">{{ $recording->status->value }}</span></td>
                <td><span class="muted" style="font-size:12px;font-weight:700;">{{ $kind }}</span></td>
                <td>{{ $recording->chunks_count }}</td>
                <td style="font-family:monospace;font-size:13px;">{{ $recording->duration ? gmdate('H:i:s', $recording->duration) : '—' }}</td>
                <td class="muted" style="font-size:13px;">{{ optional($recording->started_at)->diffForHumans() ?? '—' }}</td>
                <td style="text-align:right;"><a href="{{ route('recordings.show', $recording) }}" class="btn btn-sm" style="padding:6px 14px;">Review</a></td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted" style="text-align:center;padding:64px 0;">No recording sessions found.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div style="margin-top:24px;">{{ $recordings->links() }}</div>
@endsection
