@extends('layouts.app')

@section('title', 'Recordings')

@section('content')
<h2 style="margin-top:0;">Recordings</h2>

<form method="GET" style="margin-bottom:16px;">
    <select name="status" onchange="this.form.submit()">
        <option value="">All statuses</option>
        @foreach (['PENDING','STARTING','RECORDING','STOPPING','PROCESSING','COMPLETED','FAILED'] as $status)
            <option value="{{ $status }}" {{ request('status') === $status ? 'selected' : '' }}>{{ $status }}</option>
        @endforeach
    </select>
</form>

<div class="card">
    <table>
        <thead>
            <tr><th>Recording</th><th>Device</th><th>Status</th><th>Preset</th><th>Chunks</th><th>Duration</th><th>Started</th><th></th></tr>
        </thead>
        <tbody>
        @forelse ($recordings as $recording)
            <tr>
                <td><code>{{ substr($recording->uuid, 0, 8) }}</code></td>
                <td>{{ $recording->device->name ?? $recording->device->device_uuid }}</td>
                <td><span class="badge {{ $recording->status->value }}">{{ $recording->status->value }}</span></td>
                <td>{{ $recording->preset->value }}</td>
                <td>{{ $recording->chunks_count }}</td>
                <td>{{ $recording->duration ? gmdate('H:i:s', $recording->duration) : '—' }}</td>
                <td class="muted">{{ optional($recording->started_at)->diffForHumans() ?? '—' }}</td>
                <td><a href="{{ route('recordings.show', $recording) }}">View</a></td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted">No recordings found.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div style="margin-top:16px;">{{ $recordings->links() }}</div>
@endsection
