<?php

namespace App\Services;

use App\Enums\RecordingSource;
use App\Enums\RecordingStatus;
use App\Events\RecordingStarted;
use App\Models\Device;
use App\Models\Recording;
use Illuminate\Support\Facades\DB;

class DeviceRecordingService
{
    /**
     * Register a device-initiated recording. Used both for "device is
     * online now and just started recording" and "device is registering a
     * recording that already fully happened while offline" — the caller
     * always supplies the device-generated client_recording_id and the
     * device's own started_at, so there is a single code path for both
     * cases (see plan §1.2).
     */
    public function create(Device $device, array $data): Recording
    {
        return DB::transaction(function () use ($device, $data) {
            // Idempotent on client_recording_id: a retried registration
            // call (e.g. connectivity drop right after 201) must not
            // create a duplicate Recording row.
            $existing = Recording::query()->where('uuid', $data['client_recording_id'])->first();
            if ($existing) {
                return $existing;
            }

            $recording = Recording::query()->create([
                'uuid' => $data['client_recording_id'],
                'device_id' => $device->id,
                'status' => RecordingStatus::RECORDING,
                'preset' => $data['preset'],
                'encoder' => $data['encoder'] ?? null,
                'sample_rate' => $data['sample_rate'] ?? null,
                'bitrate' => $data['bitrate'] ?? null,
                'channels' => $data['channels'] ?? null,
                'started_at' => $data['started_at'], // device-reported, not now()
                'source' => RecordingSource::SCHEDULE_DEVICE,
            ]);

            // Do NOT flip Device::status here — see DeviceRecordingService
            // rationale in the plan: the heartbeat loop is the correct,
            // already-existing mechanism for real-time device status.

            RecordingStarted::dispatch($recording->fresh()); // reuse existing broadcast so dashboard live-updates

            return $recording;
        });
    }
}
