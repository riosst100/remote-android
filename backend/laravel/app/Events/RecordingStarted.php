<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsToAdminAndRecording;
use App\Models\Recording;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RecordingStarted implements ShouldBroadcast
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
        return 'RecordingStarted';
    }

    public function broadcastWith(): array
    {
        return [
            'recording_id' => $this->recording->uuid,
            'device_id' => $this->recording->device_id,
            'status' => $this->recording->status->value,
            'encoder' => $this->recording->encoder,
            'sample_rate' => $this->recording->sample_rate,
            'bitrate' => $this->recording->bitrate,
            'channels' => $this->recording->channels,
            'started_at' => optional($this->recording->started_at)->toIso8601String(),
        ];
    }
}
