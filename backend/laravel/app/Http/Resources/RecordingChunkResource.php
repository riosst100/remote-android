<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecordingChunkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'chunk_number' => $this->chunk_number,
            'size' => $this->size,
            'duration' => $this->duration,
            'mime_type' => $this->mime_type,
            'checksum' => $this->checksum,
            'uploaded_at' => optional($this->uploaded_at)->toIso8601String(),
        ];
    }
}
