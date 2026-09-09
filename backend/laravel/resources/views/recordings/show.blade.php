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
    <h3 style="margin-top:0;">Chunks ({{ $recording->chunks->count() }})</h3>
    <table>
        <thead><tr><th>#</th><th>Size</th><th>Duration</th><th>MIME</th><th>Uploaded</th></tr></thead>
        <tbody>
        @forelse ($recording->chunks as $chunk)
            <tr>
                <td>{{ $chunk->chunk_number }}</td>
                <td>{{ number_format($chunk->size / 1024, 1) }} KB</td>
                <td>{{ $chunk->duration }}s</td>
                <td>{{ $chunk->mime_type }}</td>
                <td class="muted">{{ optional($chunk->uploaded_at)->diffForHumans() }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">No chunks uploaded (or already merged into the final file).</td></tr>
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

    channel.bind('RecordingStatusChanged', (data) => {
        setStatus(data.status);
        showError(data.error_message);
    });
    channel.bind('RecordingStarted', (data) => setStatus(data.status));
    channel.bind('RecordingStopped', (data) => setStatus(data.status));
    channel.bind('ChunkUploaded', () => {
        // A new chunk landed — reload to show it in the chunk table below.
        // (Kept as a full reload rather than a DOM patch: chunk rows carry
        // several fields and this page is a detail view someone dwells on,
        // not a list glanced at repeatedly, so the trade-off favors simplicity.)
        location.reload();
    });
    channel.bind('RecordingCompleted', () => location.reload());
    channel.bind('RecordingFailed', (data) => {
        setStatus('FAILED');
        showError(data.error_message);
    });

    document.getElementById('realtime-indicator').textContent = '● live';
});
</script>
@endpush
