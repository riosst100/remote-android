<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Recording;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_devices_index_shows_each_devices_most_recent_recording_only(): void
    {
        $this->actingAs(User::factory()->create());

        $deviceA = Device::factory()->create(['name' => 'Device A']);
        $deviceB = Device::factory()->create(['name' => 'Device B']);

        $olderA = Recording::factory()->create(['device_id' => $deviceA->id]);
        $newerA = Recording::factory()->create(['device_id' => $deviceA->id]);
        $onlyB = Recording::factory()->create(['device_id' => $deviceB->id]);

        $response = $this->get('/devices');

        $response->assertOk();
        $response->assertSee(substr($newerA->uuid, 0, 8));
        $response->assertSee(substr($onlyB->uuid, 0, 8));
        $response->assertDontSee(substr($olderA->uuid, 0, 8));
    }

    public function test_device_detail_page_shows_capability_based_preset_previews(): void
    {
        $this->actingAs(User::factory()->create());

        $device = Device::factory()->create([
            'capabilities' => ['encoders' => ['aac'], 'sample_rates' => [44100]],
        ]);

        $response = $this->get("/devices/{$device->id}");

        $response->assertOk();
        $response->assertSee('44100 Hz');
    }
}
