@extends('layouts.app')

@section('title', 'Recording ' . substr($recording->uuid, 0, 8))

@section('content')
<p><a href="{{ route('recordings.index') }}">&larr; Recordings</a></p>
<h2 style="margin-top:0;">Recording <code>{{ $recording->uuid }}</code> <span id="realtime-indicator" class="muted" style="font-size:12px;font-weight:400;"></span></h2>

<div id="error-banner" @if (! $recording->error_message) hidden @endif class="card" style="border-color:var(--danger);margin-bottom:16px;">
    <strong style="color:var(--danger);">Error:</strong> <span id="error-message">{{ $recording->error_message }}</span>
</div>

<div class="grid cols-2" style="margin-bottom:24px;">
    <div class="card">
        <h3 style="margin-top:0;">Overview</h3>
        <table>
            <tr><th>Device</th><td><a href="{{ route('devices.show', $recording->device) }}">{{ $recording->device->name ?? $recording->device->device_uuid }}</a></td></tr>
            <tr><th>Status</th><td><span id="recording-status" class="badge {{ $recording->status->value }}">{{ $recording->status->value }}</span></td></tr>
            <tr><th>Source</th><td><span class="badge">{{ $recording->source->value }}</span></td></tr>
            <tr><th>Started</th><td>{{ optional($recording->started_at)->toDayDateTimeString() ?? '—' }}</td></tr>
            <tr><th>Stopped</th><td>{{ optional($recording->stopped_at)->toDayDateTimeString() ?? '—' }}</td></tr>
            <tr><th>Duration</th><td>{{ $recording->duration ? gmdate('H:i:s', $recording->duration) : '—' }}</td></tr>
        </table>
    </div>
    <div class="card">
        <h3 style="margin-top:0;">Audio Configuration</h3>
        <table>
            <tr><th>Preset</th><td>{{ $recording->preset->value }}</td></tr>
            <tr><th>Encoder</th><td>{{ $recording->encoder ?? '—' }}</td></tr>
            <tr><th>Sample Rate</th><td>{{ $recording->sample_rate ? $recording->sample_rate.' Hz' : '—' }}</td></tr>
            <tr><th>Bitrate</th><td>{{ $recording->bitrate ? number_format($recording->bitrate / 1000).' kbps' : '—' }}</td></tr>
            <tr><th>Channels</th><td>{{ $recording->channels ?? '—' }}</td></tr>
        </table>
    </div>
</div>

<div class="card" style="margin-bottom:24px;">
    <h3 style="margin-top:0;">Upload &amp; Merge Progress</h3>
    <table>
        <tr>
            <th>Parts uploaded</th>
            <td id="upload-progress-text">{{ $recording->chunks->count() }} part{{ $recording->chunks->count() === 1 ? '' : 's' }} received so far</td>
        </tr>
        <tr>
            <th>Merge status</th>
            <td id="merge-status-text">
                @switch($recording->status->value)
                    @case('COMPLETED')
                        Merged into the final file below.
                        @break
                    @case('PROCESSING')
                        Device finished recording — waiting for all parts to arrive, then merging.
                        @break
                    @case('STOPPING')
                        Device is still uploading parts.
                        @break
                    @case('FAILED')
                        Merge did not complete — see the error above.
                        @break
                    @default
                        Recording in progress.
                @endswitch
            </td>
        </tr>
    </table>
</div>

<div class="card" style="margin-bottom:24px;">
    <h3 style="margin-top:0;">Final File</h3>
    @if ($recording->file_path)
        <table>
            <tr><th>Size</th><td>{{ number_format($recording->file_size / 1024, 1) }} KB</td></tr>
            <tr><th>MIME Type</th><td>{{ $recording->mime_type }}</td></tr>
        </table>
        <a class="btn primary" href="{{ route('recordings.download', $recording) }}">Download</a>
    @else
        <p class="muted">Not finalized yet.</p>
    @endif
</div>

<div class="card">
    <h3 style="margin-top:0;">Parts (<span id="chunk-count">{{ $recording->chunks->count() }}</span>)</h3>
    <table>
        <thead><tr><th>#</th><th>Size</th><th>MIME</th><th>Uploaded</th></tr></thead>
        <tbody id="chunk-table-body">
        @forelse ($recording->chunks as $chunk)
            <tr>
                <td>{{ $chunk->chunk_number }}</td>
                <td>{{ number_format($chunk->size / 1024, 1) }} KB</td>
                <td>{{ $chunk->mime_type }}</td>
                <td class="muted">{{ optional($chunk->uploaded_at)->diffForHumans() }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">No parts uploaded yet (or already merged into the final file).</td></tr>
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
