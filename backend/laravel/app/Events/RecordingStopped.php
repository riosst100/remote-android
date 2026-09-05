<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsToAdminAndRecording;
use App\Models\Recording;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RecordingStopped implements ShouldBroadcast
{
    use BroadcastsToAdminAndRecording, Dispatchable, SerializesModels;

    public int $deviceId;

    public string $recordingUuid;

    public function __construct(public Recording $recording)
    {
        $this->deviceId = $recording->device_id;
        $this->recordingUuid = $recording->uuid;
    }

    public function broadcastAs(): string
    {
        return 'RecordingStopped';
    }

    public function broadcastWith(): array
    {
        return [
            'recording_id' => $this->recording->uuid,
            'device_id' => $this->recording->device_id,
            'status' => $this->recording->status->value,
            'stopped_at' => optional($this->recording->stopped_at)->toIso8601String(),
        ];
    }
}
