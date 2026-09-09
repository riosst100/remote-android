<?php

namespace Tests\Feature;

use App\Enums\DeviceStatus;
use App\Enums\RecordingStatus;
use App\Models\Device;
use App\Models\Recording;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeviceRecordingEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_device_can_register_a_recording_it_already_started(): void
    {
        $device = Device::factory()->create();
        $token = $device->createToken('test')->plainTextToken;

        $clientRecordingId = (string) \Illuminate\Support\Str::uuid();
        $startedAt = Carbon::now()->subHours(2);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/devices/recordings', [
                'preset' => 'HIGH',
                'client_recording_id' => $clientRecordingId,
                'started_at' => $startedAt->toIso8601String(),
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.uuid', $clientRecordingId);
        $response->assertJsonPath('data.status', 'RECORDING');
        $response->assertJsonPath('data.source', 'SCHEDULE_DEVICE');

        $recording = Recording::query()->where('uuid', $clientRecordingId)->firstOrFail();
        $this->assertSame(RecordingStatus::RECORDING, $recording->status);
        $this->assertSame($startedAt->toDateTimeString(), $recording->started_at->toDateTimeString());
    }

    public function test_registration_is_idempotent_on_client_recording_id(): void
    {
        $device = Device::factory()->create();
        $token = $device->createToken('test')->plainTextToken;

        $clientRecordingId = (string) \Illuminate\Support\Str::uuid();
        $payload = [
            'preset' => 'HIGH',
            'client_recording_id' => $clientRecordingId,
            'started_at' => Carbon::now()->subMinutes(10)->toIso8601String(),
        ];

        $first = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/devices/recordings', $payload);
        $second = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/devices/recordings', $payload);

        $first->assertCreated();
        $second->assertCreated();
        $this->assertSame($first->json('data.uuid'), $second->json('data.uuid'));
        $this->assertSame(1, Recording::query()->where('uuid', $clientRecordingId)->count());
    }

    public function test_a_device_can_then_upload_chunks_and_complete_using_the_registered_recording(): void
    {
        Storage::fake(config('recorder.storage_disk'));

        $device = Device::factory()->create();
        $token = $device->createToken('test')->plainTextToken;

        $clientRecordingId = (string) \Illuminate\Support\Str::uuid();

        $register = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/devices/recordings', [
                'preset' => 'HIGH',
                'client_recording_id' => $clientRecordingId,
                'started_at' => Carbon::now()->subMinutes(30)->toIso8601String(),
            ]);
        $register->assertCreated();

        $chunkContent = 'chunk-data-';
        $chunkResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->post("/api/recordings/{$clientRecordingId}/chunks", [
                'chunk_number' => 1,
                'checksum' => hash('sha256', $chunkContent),
                'duration' => 20,
                'file' => UploadedFile::fake()->createWithContent('c1.aac', $chunkContent),
            ]);
        $chunkResponse->assertCreated();

        $completeResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/recordings/{$clientRecordingId}/complete");
        $completeResponse->assertOk();
        $completeResponse->assertJsonPath('data.status', 'COMPLETED');

        $recording = Recording::query()->where('uuid', $clientRecordingId)->firstOrFail();
        $this->assertNotNull($recording->file_path);
        Storage::disk(config('recorder.storage_disk'))->assertExists($recording->file_path);
    }

    public function test_a_recording_created_by_one_device_is_not_visible_via_another_devices_chunk_upload(): void
    {
        $device = Device::factory()->create();
        $token = $device->createToken('test')->plainTextToken;

        $otherDevice = Device::factory()->create();
        $otherToken = $otherDevice->createToken('test')->plainTextToken;

        $clientRecordingId = (string) \Illuminate\Support\Str::uuid();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/devices/recordings', [
                'preset' => 'HIGH',
                'client_recording_id' => $clientRecordingId,
                'started_at' => Carbon::now()->subMinutes(5)->toIso8601String(),
            ])->assertCreated();

        // Sanctum's guard caches the resolved user for the lifetime of the
        // application instance; since RefreshDatabase keeps the app booted
        // across requests within a single test method, authenticating as a
        // second device in the same test requires clearing that cache
        // first, or the previous device's resolved user leaks through.
        auth()->forgetGuards();

        $chunkContent = 'chunk-data-';
        $response = $this->withHeader('Authorization', "Bearer {$otherToken}")
            ->post("/api/recordings/{$clientRecordingId}/chunks", [
                'chunk_number' => 1,
                'checksum' => hash('sha256', $chunkContent),
                'duration' => 20,
                'file' => UploadedFile::fake()->createWithContent('c1.aac', $chunkContent),
            ]);

        $response->assertStatus(403);
    }

    public function test_creating_a_recording_does_not_change_device_status(): void
    {
        $device = Device::factory()->create(['status' => DeviceStatus::ONLINE]);
        $token = $device->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/devices/recordings', [
                'preset' => 'HIGH',
                'client_recording_id' => (string) \Illuminate\Support\Str::uuid(),
                'started_at' => Carbon::now()->subMinutes(5)->toIso8601String(),
            ])->assertCreated();

        $device->refresh();
        $this->assertSame(DeviceStatus::ONLINE, $device->status);
    }
}
