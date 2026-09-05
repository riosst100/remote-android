<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsToAdminAndRecording;
use App\Models\DeviceCommand;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RecordingStopRequested implements ShouldBroadcast
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
        return 'RecordingStopRequested';
    }

    public function broadcastWith(): array
    {
        return [
            'command_id' => $this->command->command_id,
            'command' => $this->command->command->value,
            'recording_id' => $this->recordingUuid,
            'device_id' => $this->command->device->device_uuid,
            'timestamp' => $this->command->created_at->toIso8601String(),
        ];
    }
}
