@extends('layouts.app')

@section('title', 'Devices')

@section('content')
<h2 style="margin-top:0;">Devices</h2>
<div class="card">
    <table>
        <thead>
            <tr><th>Device</th><th>Model</th><th>Android</th><th>Status</th><th>Last Seen</th><th>Current Recording</th><th>Actions</th></tr>
        </thead>
        <tbody id="devices-table-body">
            @forelse ($devices as $device)
            @php $active = $device->recordings->first(fn($r) => !$r->status->isTerminal()); @endphp
            <tr data-device-id="{{ $device->id }}">
                <td><a href="{{ route('devices.show', $device) }}">{{ $device->name ?? $device->device_uuid }}</a></td>
                <td>{{ $device->manufacturer }} {{ $device->model }}</td>
                <td>{{ $device->android_version }}</td>
                <td class="device-status"><span class="badge {{ $device->status->value }}">{{ $device->status->value }}</span></td>
                <td class="device-last-seen muted">{{ optional($device->last_seen_at)->diffForHumans() ?? 'never' }}</td>
                <td class="device-recording">
                    @if ($active)
                        <a href="{{ route('recordings.show', $active) }}">{{ substr($active->uuid, 0, 8) }} ({{ $active->status->value }})</a>
                    @else
                        <span class="muted">—</span>
                    @endif
                </td>
                <td>
                    <select class="preset-select" data-device="{{ $device->id }}" {{ $active ? 'disabled' : '' }}>
                        @foreach (['LOW','MEDIUM','HIGH','LOSSLESS'] as $preset)
                            <option value="{{ $preset }}" {{ $preset === 'HIGH' ? 'selected' : '' }}>{{ $preset }}</option>
                        @endforeach
                    </select>
                    <button class="primary start-btn" data-device="{{ $device->id }}" {{ $active || $device->status->value === 'OFFLINE' ? 'disabled' : '' }}>Start</button>
                    <button class="danger stop-btn" data-recording="{{ $active->uuid ?? '' }}" {{ $active ? '' : 'disabled' }}>Stop</button>
                </td>
            </tr>
            @empty
            <tr><td colspan="7" class="muted">No devices have registered yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('click', async (e) => {
    if (e.target.classList.contains('start-btn')) {
        const deviceId = e.target.dataset.device;
        const preset = document.querySelector(`.preset-select[data-device="${deviceId}"]`).value;
        e.target.disabled = true;
        try {
            await window.apiFetch('/api/recordings/start', { method: 'POST', body: JSON.stringify({ device_id: Number(deviceId), preset }) });
            window.showToast('Recording start requested.');
            setTimeout(() => location.reload(), 800);
        } catch (err) {
            window.showToast(err.message, true);
            e.target.disabled = false;
        }
    }

    if (e.target.classList.contains('stop-btn')) {
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
    }
});
</script>
@endpush
