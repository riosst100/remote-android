<?php

namespace App\Services;

use App\Enums\CommandStatus;
use App\Enums\CommandType;
use App\Enums\DeviceStatus;
use App\Enums\RecordingPreset;
use App\Enums\RecordingSource;
use App\Enums\RecordingStatus;
use App\Events\DeviceStatusChanged;
use App\Events\RecordingFailed;
use App\Events\RecordingStarted;
use App\Events\RecordingStartRequested;
use App\Events\RecordingStopped;
use App\Events\RecordingStopRequested;
use App\Exceptions\DeviceUnavailableException;
use App\Jobs\StopVideoRecording;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Recording;
use App\Models\RecordingChunk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecordingLifecycleService
{
    public function __construct(private readonly AudioConfigurationResolver $configResolver) {}

    /**
     * Create a recording session and dispatch a START_RECORDING command to
     * the device. Idempotent per-device: if the device already has an
     * active (non-terminal) recording, that recording is returned instead
     * of creating a second one.
     */
    public function start(Device $device, RecordingPreset $preset): Recording
    {
        return DB::transaction(function () use ($device, $preset) {
            $device = Device::query()->lockForUpdate()->findOrFail($device->id);

            $active = $device->recordings()
                ->whereNotIn('status', [RecordingStatus::COMPLETED, RecordingStatus::FAILED])
                ->latest('id')
                ->first();

            if ($active) {
                return $active;
            }

            if ($device->status === DeviceStatus::OFFLINE) {
                throw new DeviceUnavailableException('Device is offline and cannot start a recording.');
            }

            $target = $this->configResolver->resolve($device, $preset);

            $recording = Recording::query()->create([
                'uuid' => (string) Str::uuid(),
                'device_id' => $device->id,
                'status' => RecordingStatus::STARTING,
                'preset' => $preset,
                'source' => RecordingSource::ADMIN,
                'encoder' => $target['encoder'],
                'sample_rate' => $target['sample_rate'],
                'bitrate' => $target['bitrate'],
                'channels' => $target['channels'],
            ]);

            $command = DeviceCommand::query()->create([
                'device_id' => $device->id,
                'recording_id' => $recording->id,
                'command_id' => (string) Str::uuid(),
                'command' => CommandType::START_RECORDING,
                'payload' => [
                    'preset' => $preset->value,
                    'encoder' => $target['encoder'],
                    'sample_rate' => $target['sample_rate'],
                    'bitrate' => $target['bitrate'],
                    'channels' => $target['channels'],
                ],
                'status' => CommandStatus::SENT,
                'sent_at' => now(),
            ]);

            $command->load(['device', 'recording']);
            RecordingStartRequested::dispatch($command);

            return $recording;
        });
    }

    /**
     * Dispatch a STOP_RECORDING command. Idempotent: if the recording is
     * already stopping/finalizing/finished, the existing state is returned
     * without sending a duplicate command.
     */
    public function stop(Recording $recording): Recording
    {
        return DB::transaction(function () use ($recording) {
            $recording = Recording::query()->lockForUpdate()->findOrFail($recording->id);

            if (in_array($recording->status, [
                RecordingStatus::STOPPING,
                RecordingStatus::PROCESSING,
                RecordingStatus::COMPLETED,
                RecordingStatus::FAILED,
            ], true)) {
                $this->abandonIfStopNeverReceived($recording, CommandType::STOP_RECORDING);

                return $recording;
            }

            $recording->forceFill(['status' => RecordingStatus::STOPPING])->save();

            $command = DeviceCommand::query()->create([
                'device_id' => $recording->device_id,
                'recording_id' => $recording->id,
                'command_id' => (string) Str::uuid(),
                'command' => CommandType::STOP_RECORDING,
                'payload' => [],
                'status' => CommandStatus::SENT,
                'sent_at' => now(),
            ]);

            $command->load(['device', 'recording']);
            RecordingStopRequested::dispatch($command);

            return $recording;
        });
    }

    /**
     * Create a VIDEO recording session and dispatch START_VIDEO. Mirrors
     * start() but there is no audio config to resolve — the device records
     * at a fixed 1080p profile. Idempotent per-device like start().
     */
    public function startVideo(Device $device): Recording
    {
        $recording = DB::transaction(function () use ($device) {
            $device = Device::query()->lockForUpdate()->findOrFail($device->id);

            $active = $device->recordings()
                ->whereNotIn('status', [RecordingStatus::COMPLETED, RecordingStatus::FAILED])
                ->latest('id')
                ->first();

            if ($active) {
                return $active;
            }

            if ($device->status === DeviceStatus::OFFLINE) {
                throw new DeviceUnavailableException('Device is offline and cannot start a recording.');
            }

            $recording = Recording::query()->create([
                'uuid' => (string) Str::uuid(),
                'device_id' => $device->id,
                'status' => RecordingStatus::STARTING,
                'media_kind' => \App\Enums\MediaKind::VIDEO,
                'preset' => RecordingPreset::HIGH,
                'source' => RecordingSource::ADMIN,
                'encoder' => 'h264',
                'sample_rate' => 0,
                'bitrate' => 0,
                'channels' => 0,
            ]);

            $command = DeviceCommand::query()->create([
                'device_id' => $device->id,
                'recording_id' => $recording->id,
                'command_id' => (string) Str::uuid(),
                'command' => CommandType::START_VIDEO,
                'payload' => ['quality' => '1080p'],
                'status' => CommandStatus::SENT,
                'sent_at' => now(),
            ]);

            $command->load(['device', 'recording']);
            RecordingStartRequested::dispatch($command);

            return $recording;
        });

        // Arm the auto-stop only for a freshly-started recording (not the
        // idempotent return of an already-active one), when the device has a
        // maximum video duration configured.
        if ($recording->wasRecentlyCreated) {
            $maxMinutes = (int) (Device::query()->whereKey($device->id)->value('max_video_minutes') ?? 0);
            if ($maxMinutes > 0) {
                StopVideoRecording::dispatch($recording->id)->delay(now()->addMinutes($maxMinutes));
            }
        }

        return $recording;
    }

    /**
     * Dispatch STOP_VIDEO. Idempotent like stop().
     */
    public function stopVideo(Recording $recording): Recording
    {
        return DB::transaction(function () use ($recording) {
            $recording = Recording::query()->lockForUpdate()->findOrFail($recording->id);

            if (in_array($recording->status, [
                RecordingStatus::STOPPING,
                RecordingStatus::PROCESSING,
                RecordingStatus::COMPLETED,
                RecordingStatus::FAILED,
            ], true)) {
                $this->abandonIfStopNeverReceived($recording, CommandType::STOP_VIDEO);

                return $recording;
            }

            $recording->forceFill(['status' => RecordingStatus::STOPPING])->save();

            $command = DeviceCommand::query()->create([
                'device_id' => $recording->device_id,
                'recording_id' => $recording->id,
                'command_id' => (string) Str::uuid(),
                'command' => CommandType::STOP_VIDEO,
                'payload' => [],
                'status' => CommandStatus::SENT,
                'sent_at' => now(),
            ]);

            $command->load(['device', 'recording']);
            RecordingStopRequested::dispatch($command);

            return $recording;
        });
    }

    /**
     * Acknowledge that the device actually started capturing audio.
     * Records the *actual* configuration the device settled on, which may
     * differ from what was proposed if the proposed config was unsupported.
     */
    public function acknowledgeStarted(DeviceCommand $command, array $actualConfig): Recording
    {
        return DB::transaction(function () use ($command, $actualConfig) {
            $recording = Recording::query()->lockForUpdate()->findOrFail($command->recording_id);

            if ($recording->status === RecordingStatus::RECORDING) {
                return $recording;
            }

            $recording->forceFill([
                'status' => RecordingStatus::RECORDING,
                'encoder' => $actualConfig['encoder'] ?? $recording->encoder,
                'sample_rate' => $actualConfig['sample_rate'] ?? $recording->sample_rate,
                'bitrate' => $actualConfig['bitrate'] ?? $recording->bitrate,
                'channels' => $actualConfig['channels'] ?? $recording->channels,
                'started_at' => now(),
            ])->save();

            $command->forceFill([
                'status' => CommandStatus::COMPLETED,
                'received_at' => $command->received_at ?? now(),
                'completed_at' => now(),
            ])->save();

            $device = $recording->device;
            $device->forceFill(['status' => DeviceStatus::RECORDING])->save();
            DeviceStatusChanged::dispatch($device);

            RecordingStarted::dispatch($recording->fresh());

            return $recording;
        });
    }

    public function acknowledgeStopped(DeviceCommand $command): Recording
    {
        return DB::transaction(function () use ($command) {
            $recording = Recording::query()->lockForUpdate()->findOrFail($command->recording_id);

            if (in_array($recording->status, [RecordingStatus::PROCESSING, RecordingStatus::COMPLETED, RecordingStatus::FAILED], true)) {
                return $recording;
            }

            $recording->forceFill([
                'status' => RecordingStatus::PROCESSING,
                'stopped_at' => now(),
                'duration' => $recording->started_at ? (int) round($recording->started_at->diffInSeconds(now(), true)) : null,
            ])->save();

            $command->forceFill([
                'status' => CommandStatus::COMPLETED,
                'received_at' => $command->received_at ?? now(),
                'completed_at' => now(),
            ])->save();

            $device = $recording->device;
            $device->forceFill(['status' => DeviceStatus::ONLINE])->save();
            DeviceStatusChanged::dispatch($device);

            RecordingStopped::dispatch($recording->fresh());

            return $recording;
        });
    }

    /**
     * Fail recordings that have sat in STOPPING/PROCESSING with no progress
     * (no new chunk uploaded, no command ack) past the configured timeout.
     *
     * A recording normally leaves STOPPING via the device's STOP_RECORDING
     * ack, and leaves PROCESSING via its /complete call. Both of those are
     * single, unretried round-trips over a websocket push + device-initiated
     * HTTP call: if the device drops the connection, crashes, or is killed
     * by the OS at the wrong moment, neither call ever happens and the
     * recording (and the device's RECORDING status) would otherwise be
     * stuck forever with no automatic recovery.
     */
    public function failStuckRecordings(): int
    {
        $timeout = config('recorder.stuck_recording_timeout_seconds');
        $cutoff = now()->subSeconds($timeout);
        $count = 0;

        Recording::query()
            ->whereIn('status', [RecordingStatus::STOPPING, RecordingStatus::PROCESSING])
            ->where('updated_at', '<', $cutoff)
            ->each(function (Recording $recording) use ($cutoff, &$count) {
                $lastChunkAt = RecordingChunk::query()
                    ->where('recording_id', $recording->id)
                    ->max('created_at');

                if ($lastChunkAt && $lastChunkAt >= $cutoff) {
                    return;
                }

                DB::transaction(function () use ($recording) {
                    $recording = Recording::query()->lockForUpdate()->findOrFail($recording->id);

                    if ($recording->status->isTerminal()) {
                        return;
                    }

                    $recording->forceFill([
                        'status' => RecordingStatus::FAILED,
                        'error_message' => 'Recording timed out waiting for the device to acknowledge the stop/finalize step.',
                    ])->save();

                    RecordingFailed::dispatch($recording->fresh());

                    $device = $recording->device;
                    if ($device && $device->status === DeviceStatus::RECORDING) {
                        $device->forceFill(['status' => DeviceStatus::ONLINE])->save();
                        DeviceStatusChanged::dispatch($device);
                    }
                });

                $count++;
            });

        return $count;
    }

    /**
     * A repeated Stop on a STOPPING recording whose stop command the device
     * never even received (a dropped websocket push) fails the recording
     * at once, rather than leaving the dashboard stuck until the 30-minute
     * stuck-recording sweep. A stop the device did receive is left alone:
     * it may still be finalizing or uploading.
     */
    private function abandonIfStopNeverReceived(Recording $recording, CommandType $stopType): void
    {
        if ($recording->status !== RecordingStatus::STOPPING) {
            return;
        }

        $stop = DeviceCommand::query()
            ->where('recording_id', $recording->id)
            ->where('command', $stopType)
            ->latest('id')
            ->first();

        $grace = config('recorder.unreceived_stop_grace_seconds', 60);
        if (! $stop || $stop->received_at || $stop->sent_at?->gt(now()->subSeconds($grace))) {
            return;
        }

        $stop->forceFill([
            'status' => CommandStatus::FAILED,
            'completed_at' => now(),
            'error_message' => 'Device never received the stop command.',
        ])->save();

        $recording->forceFill([
            'status' => RecordingStatus::FAILED,
            'error_message' => 'Device never received the stop command; stopped from the dashboard.',
        ])->save();

        RecordingFailed::dispatch($recording->fresh());

        $device = $recording->device;
        if ($device && $device->status === DeviceStatus::RECORDING) {
            $device->forceFill(['status' => DeviceStatus::ONLINE])->save();
            DeviceStatusChanged::dispatch($device);
        }
    }
}
