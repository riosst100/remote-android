<?php

namespace Tests\Feature;

use App\Enums\DeviceStatus;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    private function deviceWithToken(): array
    {
        $device = Device::factory()->create(['status' => DeviceStatus::OFFLINE, 'last_seen_at' => now()->subHour()]);
        $token = $device->createToken('test')->plainTextToken;

        return [$device, $token];
    }

    public function test_heartbeat_marks_device_online_and_updates_last_seen(): void
    {
        [$device, $token] = $this->deviceWithToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/devices/heartbeat', []);

        $response->assertOk();
        $response->assertJsonPath('device.status', 'ONLINE');

        $device->refresh();
        $this->assertSame(DeviceStatus::ONLINE, $device->status);
        $this->assertTrue($device->last_seen_at->gt(now()->subSeconds(5)));
    }

    public function test_heartbeat_requires_authentication(): void
    {
        $this->postJson('/api/devices/heartbeat', [])->assertUnauthorized();
    }

    public function test_stale_devices_are_marked_offline_by_the_sweep_command(): void
    {
        [$device] = $this->deviceWithToken();
        $device->forceFill(['status' => DeviceStatus::ONLINE, 'last_seen_at' => now()->subMinutes(10)])->save();

        $this->artisan('devices:sweep-stale')->assertSuccessful();

        $device->refresh();
        $this->assertSame(DeviceStatus::OFFLINE, $device->status);
    }
}
