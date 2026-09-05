<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsToAdminAndRecording;
use App\Models\DeviceCommand;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RecordingStartRequested implements ShouldBroadcast
{
    use BroadcastsToAdminAndRecording, Dispatchable, SerializesModels;

    public int $deviceId;

    public string $recordingUuid;

    public function __construct(public DeviceCommand $command)
    {
        $this->deviceId = $command->device_id;
        $this->recordingUuid = $command->recording->uuid;
    }

    public function broadcastAs(): string
    {
        return 'RecordingStartRequested';
    }

    public function broadcastWith(): array
    {
        return [
            'command_id' => $this->command->command_id,
            'command' => $this->command->command->value,
            'recording_id' => $this->recordingUuid,
            // "device_id" here is the device's UUID, per the START_RECORDING
            // command contract the Android agent expects. The dashboard
            // instead needs the numeric device row id to match its DOM, so
            // that's exposed separately rather than overloading device_id
            // with two different meanings for two different consumers.
            'device_id' => $this->command->device->device_uuid,
            'admin_device_id' => $this->deviceId,
            'configuration' => $this->command->payload,
            'timestamp' => $this->command->created_at->toIso8601String(),
        ];
    }
}
