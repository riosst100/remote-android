@extends('layouts.app')

@section('title', 'Recording ' . substr($recording->uuid, 0, 8))

@section('content')
<div style="margin-bottom:24px;">
    <a href="{{ route('recordings.index') }}" class="muted" style="font-size:13px;display:flex;align-items:center;gap:6px;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
        Back to Recordings
    </a>
</div>

<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:24px;margin-bottom:32px;">
    <div>
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
            <span class="badge {{ $recording->status->value }}">{{ $recording->status->value }}</span>
            <span class="badge" style="background:rgba(255,255,255,0.05);">{{ $recording->media_kind?->value ?? 'AUDIO' }}</span>
        </div>
        <h1 style="font-family:monospace;">{{ substr($recording->uuid, 0, 18) }}<span class="muted">...</span> <span id="realtime-indicator" class="muted" style="font-size:12px;font-weight:400;"></span></h1>
    </div>
    @if ($recording->file_path)
        <a class="btn primary" href="{{ route('recordings.download', $recording) }}" style="padding:12px 24px;">Download Final File</a>
    @endif
</div>

<div id="error-banner" @if (! $recording->error_message) hidden @endif class="card" style="border-color:var(--danger);background:rgba(239,68,68,0.05);margin-bottom:32px;">
    <div style="display:flex;align-items:center;gap:12px;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--danger);"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <div>
            <strong style="color:var(--danger);display:block;font-size:13px;">Execution Error</strong>
            <span id="error-message" style="font-size:14px;">{{ $recording->error_message }}</span>
        </div>
    </div>
</div>

<div class="grid cols-2" style="margin-bottom:32px;">
    <div class="card">
        <h3 style="margin-bottom:20px;">Session Overview</h3>
        <table class="muted" style="font-size:13px;">
            <tr><th style="width:140px;">Device</th><td><a href="{{ route('devices.show', $recording->device) }}" style="font-weight:700;color:var(--text);">{{ $recording->device->name ?? $recording->device->device_uuid }}</a></td></tr>
            <tr><th>Status</th><td><span id="recording-status" class="badge {{ $recording->status->value }}">{{ $recording->status->value }}</span></td></tr>
            <tr><th>Source</th><td><span class="badge" style="background:rgba(255,255,255,0.05);">{{ $recording->source->value }}</span></td></tr>
            <tr><th>Started</th><td style="color:var(--text);">{{ optional($recording->started_at)->toDayDateTimeString() ?? '—' }}</td></tr>
            <tr><th>Stopped</th><td style="color:var(--text);">{{ optional($recording->stopped_at)->toDayDateTimeString() ?? '—' }}</td></tr>
            <tr><th>Duration</th><td style="font-family:monospace;font-size:14px;color:var(--accent);font-weight:700;">{{ $recording->duration ? gmdate('H:i:s', $recording->duration) : '—' }}</td></tr>
        </table>
    </div>
    <div class="card">
        <h3 style="margin-bottom:20px;">Configuration</h3>
        <table class="muted" style="font-size:13px;">
            <tr><th style="width:140px;">Preset</th><td style="color:var(--text);font-weight:700;">{{ $recording->preset->value }}</td></tr>
            <tr><th>Encoder</th><td>{{ $recording->encoder ?? '—' }}</td></tr>
            <tr><th>Sample Rate</th><td>{{ $recording->sample_rate ? number_format($recording->sample_rate).' Hz' : '—' }}</td></tr>
            <tr><th>Bitrate</th><td>{{ $recording->bitrate ? number_format($recording->bitrate / 1000).' kbps' : '—' }}</td></tr>
            <tr><th>Channels</th><td>{{ $recording->channels === 1 ? 'Mono' : ($recording->channels === 2 ? 'Stereo' : $recording->channels) }}</td></tr>
        </table>
    </div>
</div>

<div class="grid cols-1" style="margin-bottom:32px;">
    <div class="card">
        <h3 style="margin-bottom:20px;">Upload & Merge Status</h3>
        <div style="display:flex;gap:32px;align-items:center;padding:24px;background:rgba(255,255,255,0.02);border-radius:12px;">
            <div style="flex:1;">
                <div class="stat-label" style="margin-bottom:8px;">Chunks Received</div>
                <div id="upload-progress-text" style="font-size:24px;font-weight:800;font-family:monospace;">{{ $recording->chunks->count() }} <span class="muted" style="font-size:14px;font-weight:400;">parts</span></div>
            </div>
            <div style="flex:2;padding-left:32px;border-left:1px solid var(--border);">
                <div class="stat-label" style="margin-bottom:8px;">Merge Operation</div>
                <div id="merge-status-text" style="font-size:14px;font-weight:600;">
                    @switch($recording->status->value)
                        @case('COMPLETED') Merged successfully. Final file ready. @break
                        @case('PROCESSING') Waiting for all chunks to arrive, then merging. @break
                        @case('STOPPING') Device is still uploading remaining parts. @break
                        @case('FAILED') Merge failed or was interrupted. @break
                        @default Recording in progress. @break
                    @endswitch
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card" style="padding:0;">
    <div style="padding:24px 24px 12px;">
        <h3 style="margin:0;">Uploaded Parts (<span id="chunk-count">{{ $recording->chunks->count() }}</span>)</h3>
    </div>
    <table>
        <thead><tr><th>#</th><th>Size</th><th>MIME Type</th><th>Uploaded At</th></tr></thead>
        <tbody id="chunk-table-body">
        @forelse ($recording->chunks as $chunk)
            <tr>
                <td style="font-family:monospace;font-weight:700;">#{{ $chunk->chunk_number }}</td>
                <td>{{ number_format($chunk->size / 1024, 1) }} KB</td>
                <td class="muted" style="font-size:12px;">{{ $chunk->mime_type }}</td>
                <td class="muted" style="font-size:13px;">{{ optional($chunk->uploaded_at)->toDayDateTimeString() }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted" style="text-align:center;padding:48px 0;">No chunks received yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection

@push('scripts')
<script>
window.realtimeReady?.then((pusher) => {
    const channel = pusher.subscribe('private-recordings.{{ $recording->uuid }}');

    const setStatus = (status) => {
        const badge = document.getElementById('recording-status');
        badge.textContent = status;
        badge.className = `badge ${status}`;
    };

    const showError = (message) => {
        if (!message) return;
        document.getElementById('error-message').textContent = message;
        document.getElementById('error-banner').hidden = false;
    };

    const setMergeStatus = (status) => {
        const text = {
            COMPLETED: 'Merged into the final file below.',
            PROCESSING: 'Device finished recording — waiting for all parts to arrive, then merging.',
            STOPPING: 'Device is still uploading parts.',
            FAILED: 'Merge did not complete — see the error above.',
        }[status] ?? 'Recording in progress.';
        document.getElementById('merge-status-text').textContent = text;
    };

    channel.bind('RecordingStatusChanged', (data) => {
        setStatus(data.status);
        setMergeStatus(data.status);
        showError(data.error_message);
    });
    channel.bind('RecordingStarted', (data) => { setStatus(data.status); setMergeStatus(data.status); });
    channel.bind('RecordingStopped', (data) => { setStatus(data.status); setMergeStatus(data.status); });
    channel.bind('ChunkUploaded', (data) => {
        // A recording can have many parts arriving one at a time (a long
        // session splits into several 5-minute-ish parts) — bump the
        // visible counters in place rather than reloading the whole page
        // on every single part, which would otherwise flicker repeatedly
        // during upload. The parts table gets a lightweight appended row
        // too, so the detail is there without a full round-trip.
        const countEl = document.getElementById('chunk-count');
        const nextCount = parseInt(countEl.textContent, 10) + 1;
        countEl.textContent = nextCount;
        document.getElementById('upload-progress-text').textContent =
            `${nextCount} part${nextCount === 1 ? '' : 's'} received so far`;

        const tbody = document.getElementById('chunk-table-body');
        if (tbody.children.length === 1 && tbody.children[0].children.length === 1) {
            tbody.innerHTML = ''; // clear the "no parts yet" placeholder row
        }
        const row = document.createElement('tr');
        const sizeKb = data.size ? (data.size / 1024).toFixed(1) : '—';
        row.innerHTML = `<td>${data.chunk_number ?? nextCount}</td><td>${sizeKb} KB</td><td>audio/aac</td><td class="muted">just now</td>`;
        tbody.appendChild(row);
    });
    channel.bind('RecordingCompleted', () => {
        setMergeStatus('COMPLETED');
        location.reload();
    });
    channel.bind('RecordingFailed', (data) => {
        setStatus('FAILED');
        setMergeStatus('FAILED');
        showError(data.error_message);
    });

    document.getElementById('realtime-indicator').textContent = '● live';
});
</script>
@endpush
