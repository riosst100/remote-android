<?php

namespace Database\Factories;

use App\Models\Recording;
use Illuminate\Database\Eloquent\Factories\Factory;

class RecordingChunkFactory extends Factory
{
    public function definition(): array
    {
        return [
            'recording_id' => Recording::factory(),
            'chunk_number' => 1,
            'file_path' => 'recordings/test/chunk-1.aac',
            'size' => 12345,
            'checksum' => hash('sha256', 'test-chunk'),
            'duration' => 20,
            'mime_type' => 'audio/aac',
            'uploaded_at' => now(),
        ];
    }
}
