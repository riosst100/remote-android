<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\RecordingSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DeviceScheduleEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_device_can_fetch_its_own_active_schedules(): void
    {
        $device = Device::factory()->create();
        $token = $device->createToken('test')->plainTextToken;

        $active1 = RecordingSchedule::factory()->create([
            'device_id' => $device->id,
            'day_of_week' => 1,
            'is_active' => true,
        ]);
        $active2 = RecordingSchedule::factory()->create([
            'device_id' => $device->id,
            'day_of_week' => 3,
            'is_active' => true,
        ]);
        RecordingSchedule::factory()->create([
            'device_id' => $device->id,
            'day_of_week' => 5,
            'is_active' => false,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/devices/schedules');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$active1->id, $active2->id], $ids);
    }

    public function test_a_device_cannot_see_another_devices_schedules(): void
    {
        $device = Device::factory()->create();
        $token = $device->createToken('test')->plainTextToken;

        $otherDevice = Device::factory()->create();
        RecordingSchedule::factory()->create([
            'device_id' => $otherDevice->id,
            'is_active' => true,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/devices/schedules');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/devices/schedules')->assertUnauthorized();
    }

    public function test_fetching_schedules_marks_the_device_as_synced(): void
    {
        $device = Device::factory()->create();
        $token = $device->createToken('test')->plainTextToken;

        $schedule = RecordingSchedule::factory()->create([
            'device_id' => $device->id,
            'is_active' => true,
        ]);

        $this->assertFalse($schedule->isSyncedToDevice(), 'A schedule should not be synced before the device has ever pulled it.');
        $this->assertNull($device->fresh()->schedules_synced_at);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/devices/schedules')
            ->assertOk();

        $device->refresh();
        $this->assertNotNull($device->schedules_synced_at);
        $this->assertTrue($schedule->fresh()->isSyncedToDevice(), 'The schedule should be marked synced once the device has pulled it.');
    }

    public function test_a_schedule_edited_after_the_last_sync_is_not_considered_synced(): void
    {
        Carbon::setTestNow('2026-01-01 00:00:00');

        $device = Device::factory()->create();
        $token = $device->createToken('test')->plainTextToken;

        $schedule = RecordingSchedule::factory()->create([
            'device_id' => $device->id,
            'is_active' => true,
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/devices/schedules')
            ->assertOk();

        $this->assertTrue($schedule->fresh()->isSyncedToDevice());

        // Admin edits the schedule after the device's last pull.
        Carbon::setTestNow('2026-01-01 00:10:00');
        $schedule->forceFill(['duration_minutes' => 45])->save();

        $this->assertFalse($schedule->fresh()->isSyncedToDevice(), 'An edit made after the last sync should show as pending pull again.');

        Carbon::setTestNow();
    }
}
