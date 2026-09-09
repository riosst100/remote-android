<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\RecordingSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
