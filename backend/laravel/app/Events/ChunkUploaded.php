<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsToAdminAndRecording;
use App\Models\RecordingChunk;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChunkUploaded implements ShouldBroadcast
{
    use BroadcastsToAdminAndRecording, Dispatchable, SerializesModels;

    public int $deviceId;

    public string $recordingUuid;

    public function __construct(public RecordingChunk $chunk)
    {
        $this->deviceId = $chunk->recording->device_id;
        $this->recordingUuid = $chunk->recording->uuid;
    }

    public function broadcastAs(): string
    {
        return 'ChunkUploaded';
    }

    public function broadcastWith(): array
    {
        return [
            'recording_id' => $this->recordingUuid,
            'chunk_number' => $this->chunk->chunk_number,
            'size' => $this->chunk->size,
            'duration' => $this->chunk->duration,
        ];
    }
}
