<?php

namespace Tests\Feature;

use App\Enums\RecordingStatus;
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

    public function test_devices_index_shows_resolved_configuration_detail_per_preset_option(): void
    {
        $this->actingAs(User::factory()->create());

        Device::factory()->create([
            'capabilities' => ['encoders' => ['aac'], 'sample_rates' => [44100]],
        ]);

        $response = $this->get('/devices');

        $response->assertOk();
        // HIGH resolves to 44100Hz/192kbps when only 44100 is supported
        // (the 48000Hz rung of the ladder gets skipped).
        $response->assertSee('HIGH — AAC 44.1kHz/192kbps', escape: false);
    }

    public function test_devices_index_excludes_lossless_from_selectable_presets(): void
    {
        $this->actingAs(User::factory()->create());

        Device::factory()->create();

        $response = $this->get('/devices');

        $response->assertOk();
        $response->assertDontSee('LOSSLESS');
    }

    public function test_devices_index_exposes_the_started_at_timestamp_for_a_live_recording(): void
    {
        $this->actingAs(User::factory()->create());

        $device = Device::factory()->create();
        $startedAt = now()->subMinutes(2);
        Recording::factory()->create([
            'device_id' => $device->id,
            'status' => RecordingStatus::RECORDING,
            'started_at' => $startedAt,
        ]);

        $response = $this->get('/devices');

        $response->assertOk();
        $response->assertSee('data-started-at="'.$startedAt->toIso8601String().'"', escape: false);
    }

    public function test_devices_index_shows_the_last_completed_durations_hms_when_idle(): void
    {
        $this->actingAs(User::factory()->create());

        $device = Device::factory()->create();
        Recording::factory()->create([
            'device_id' => $device->id,
            'status' => RecordingStatus::COMPLETED,
            'duration' => 125,
        ]);

        $response = $this->get('/devices');

        $response->assertOk();
        $response->assertSee('00:02:05');
    }
}
