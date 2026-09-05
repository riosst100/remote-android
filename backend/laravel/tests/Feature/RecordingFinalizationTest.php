<?php

namespace Tests\Feature;

use App\Enums\RecordingStatus;
use App\Models\Device;
use App\Models\Recording;
use App\Services\ChunkUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecordingFinalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_completing_a_recording_merges_chunks_into_a_final_file(): void
    {
        Storage::fake(config('recorder.storage_disk'));

        $device = Device::factory()->create();
        $token = $device->createToken('test')->plainTextToken;
        $recording = Recording::factory()->create([
            'device_id' => $device->id,
            'status' => RecordingStatus::RECORDING,
            'mime_type' => 'audio/aac',
        ]);

        $service = app(ChunkUploadService::class);
        $service->store($recording, UploadedFile::fake()->createWithContent('c1.aac', 'part-one-'), 1, hash('sha256', 'part-one-'), 20);
        $service->store($recording, UploadedFile::fake()->createWithContent('c2.aac', 'part-two-'), 2, hash('sha256', 'part-two-'), 20);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/recordings/{$recording->uuid}/complete");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'COMPLETED');

        $recording->refresh();
        $this->assertNotNull($recording->file_path);
        Storage::disk(config('recorder.storage_disk'))->assertExists($recording->file_path);
        $this->assertSame('part-one-part-two-', Storage::disk(config('recorder.storage_disk'))->get($recording->file_path));

        // Chunks are cleaned up once merged into the final file.
        $this->assertSame(0, $recording->chunks()->count());
    }

    public function test_finalization_fails_when_a_chunk_is_missing_from_the_sequence(): void
    {
        Storage::fake(config('recorder.storage_disk'));

        $device = Device::factory()->create();
        $token = $device->createToken('test')->plainTextToken;
        $recording = Recording::factory()->create(['device_id' => $device->id, 'status' => RecordingStatus::RECORDING]);

        $service = app(ChunkUploadService::class);
        $service->store($recording, UploadedFile::fake()->createWithContent('c1.aac', 'one'), 1, hash('sha256', 'one'), 20);
        // chunk 2 is intentionally skipped
        $service->store($recording, UploadedFile::fake()->createWithContent('c3.aac', 'three'), 3, hash('sha256', 'three'), 20);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/recordings/{$recording->uuid}/complete");

        $response->assertUnprocessable();

        $recording->refresh();
        $this->assertSame(RecordingStatus::FAILED, $recording->status);
    }

    public function test_completing_an_already_completed_recording_is_idempotent(): void
    {
        Storage::fake(config('recorder.storage_disk'));

        $device = Device::factory()->create();
        $token = $device->createToken('test')->plainTextToken;
        $recording = Recording::factory()->create(['device_id' => $device->id, 'status' => RecordingStatus::RECORDING]);

        $service = app(ChunkUploadService::class);
        $service->store($recording, UploadedFile::fake()->createWithContent('c1.aac', 'one'), 1, hash('sha256', 'one'), 20);

        $first = $this->withHeader('Authorization', "Bearer {$token}")->postJson("/api/recordings/{$recording->uuid}/complete");
        $second = $this->withHeader('Authorization', "Bearer {$token}")->postJson("/api/recordings/{$recording->uuid}/complete");

        $first->assertOk();
        $second->assertOk();
        $this->assertSame($first->json('data.file_path') ?? true, $second->json('data.file_path') ?? true);
    }
}
