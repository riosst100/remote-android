<?php

namespace Tests\Feature;

use App\Enums\RecordingStatus;
use App\Models\Device;
use App\Models\Recording;
use App\Models\RecordingSchedule;
use App\Models\User;
use App\Services\ChunkStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeviceDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_delete_a_device_with_no_recordings(): void
    {
        $this->actingAs(User::factory()->create());
        $device = Device::factory()->create();

        $this->delete(route('devices.destroy', $device))
            ->assertRedirect(route('devices.index'));

        $this->assertDatabaseMissing('devices', ['id' => $device->id]);
    }

    public function test_deleting_a_device_cascades_recordings_and_schedules(): void
    {
        $this->actingAs(User::factory()->create());
        $device = Device::factory()->create();
        $schedule = RecordingSchedule::factory()->create(['device_id' => $device->id]);
        $recording = Recording::factory()->create(['device_id' => $device->id, 'status' => RecordingStatus::COMPLETED]);

        $this->delete(route('devices.destroy', $device));

        $this->assertDatabaseMissing('recording_schedules', ['id' => $schedule->id]);
        $this->assertDatabaseMissing('recordings', ['id' => $recording->id]);
    }

    public function test_deleting_a_device_removes_its_recordings_final_files_from_disk(): void
    {
        Storage::fake(config('recorder.storage_disk'));
        $this->actingAs(User::factory()->create());

        $device = Device::factory()->create();
        $recording = Recording::factory()->create([
            'device_id' => $device->id,
            'status' => RecordingStatus::COMPLETED,
        ]);

        $paths = app(ChunkStorageService::class);
        $directory = $paths->finalDirectoryFor($recording->uuid, $recording->created_at);
        $finalPath = "{$directory}/final.aac";
        Storage::disk(config('recorder.storage_disk'))->put($finalPath, 'fake audio bytes');

        $this->delete(route('devices.destroy', $device));

        Storage::disk(config('recorder.storage_disk'))->assertMissing($finalPath);
    }

    public function test_a_device_with_an_active_recording_cannot_be_deleted(): void
    {
        $this->actingAs(User::factory()->create());
        $device = Device::factory()->create();
        $recording = Recording::factory()->create(['device_id' => $device->id, 'status' => RecordingStatus::RECORDING]);

        $this->delete(route('devices.destroy', $device))
            ->assertRedirect();

        $this->assertDatabaseHas('devices', ['id' => $device->id]);
        $this->assertDatabaseHas('recordings', ['id' => $recording->id]);
    }

    public function test_device_deletion_requires_admin_authentication(): void
    {
        $device = Device::factory()->create();

        $this->delete(route('devices.destroy', $device))
            ->assertRedirect(route('login'));

        $this->assertDatabaseHas('devices', ['id' => $device->id]);
    }
}
