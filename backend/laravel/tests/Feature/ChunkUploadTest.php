<?php

namespace Tests\Feature;

use App\Enums\RecordingStatus;
use App\Models\Device;
use App\Models\Recording;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChunkUploadTest extends TestCase
{
    use RefreshDatabase;

    private function deviceWithRecording(): array
    {
        $device = Device::factory()->create();
        $token = $device->createToken('test')->plainTextToken;
        $recording = Recording::factory()->create(['device_id' => $device->id, 'status' => RecordingStatus::RECORDING]);

        return [$device, $token, $recording];
    }

    public function test_a_device_can_upload_a_chunk_with_a_valid_checksum(): void
    {
        Storage::fake(config('recorder.storage_disk'));
        [$device, $token, $recording] = $this->deviceWithRecording();

        $file = UploadedFile::fake()->createWithContent('chunk1.aac', 'audio-bytes-here');
        $checksum = hash('sha256', 'audio-bytes-here');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->post("/api/recordings/{$recording->uuid}/chunks", [
                'chunk_number' => 1,
                'checksum' => $checksum,
                'duration' => 20,
                'file' => $file,
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('recording_chunks', [
            'recording_id' => $recording->id,
            'chunk_number' => 1,
            'checksum' => $checksum,
        ]);
    }

    public function test_a_chunk_with_a_mismatched_checksum_is_rejected(): void
    {
        Storage::fake(config('recorder.storage_disk'));
        [, $token, $recording] = $this->deviceWithRecording();

        $file = UploadedFile::fake()->createWithContent('chunk1.aac', 'audio-bytes-here');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->post("/api/recordings/{$recording->uuid}/chunks", [
                'chunk_number' => 1,
                'checksum' => str_repeat('0', 64),
                'file' => $file,
            ]);

        $response->assertUnprocessable();
        $this->assertDatabaseMissing('recording_chunks', ['recording_id' => $recording->id]);
    }

    public function test_uploading_the_same_chunk_number_twice_does_not_duplicate_it(): void
    {
        Storage::fake(config('recorder.storage_disk'));
        [, $token, $recording] = $this->deviceWithRecording();
        $checksum = hash('sha256', 'audio-bytes-here');

        $upload = fn () => $this->withHeader('Authorization', "Bearer {$token}")
            ->post("/api/recordings/{$recording->uuid}/chunks", [
                'chunk_number' => 1,
                'checksum' => $checksum,
                'file' => UploadedFile::fake()->createWithContent('chunk1.aac', 'audio-bytes-here'),
            ]);

        $upload()->assertCreated();
        $upload()->assertCreated();

        $this->assertSame(1, $recording->chunks()->count());
    }

    public function test_a_device_cannot_upload_a_chunk_for_another_devices_recording(): void
    {
        Storage::fake(config('recorder.storage_disk'));
        [, , $recording] = $this->deviceWithRecording();

        $otherDevice = Device::factory()->create();
        $otherToken = $otherDevice->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$otherToken}")
            ->post("/api/recordings/{$recording->uuid}/chunks", [
                'chunk_number' => 1,
                'checksum' => hash('sha256', 'x'),
                'file' => UploadedFile::fake()->createWithContent('chunk1.aac', 'x'),
            ]);

        $response->assertForbidden();
    }
}
