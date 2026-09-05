<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecordingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'device_id' => $this->device_id,
            'device' => $this->whenLoaded('device', fn () => [
                'id' => $this->device->id,
                'device_uuid' => $this->device->device_uuid,
                'name' => $this->device->name,
            ]),
            'status' => $this->status->value,
            'preset' => $this->preset->value,
            'encoder' => $this->encoder,
            'sample_rate' => $this->sample_rate,
            'bitrate' => $this->bitrate,
            'channels' => $this->channels,
            'started_at' => optional($this->started_at)->toIso8601String(),
            'stopped_at' => optional($this->stopped_at)->toIso8601String(),
            'duration' => $this->duration,
            'file_size' => $this->file_size,
            'mime_type' => $this->mime_type,
            'error_message' => $this->error_message,
            'chunk_count' => $this->whenCounted('chunks'),
            'chunks' => RecordingChunkResource::collection($this->whenLoaded('chunks')),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
