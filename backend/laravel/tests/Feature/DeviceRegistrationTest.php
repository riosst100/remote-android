<?php

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeviceRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_device_can_register_and_receives_a_token(): void
    {
        $uuid = (string) Str::uuid();

        $response = $this->postJson('/api/devices/register', [
            'device_uuid' => $uuid,
            'device_name' => 'Test Phone',
            'manufacturer' => 'Google',
            'model' => 'Pixel 8',
            'android_version' => '15',
            'app_version' => '1.0.0',
            'audio_capabilities' => [
                'encoders' => ['aac'],
                'sample_rates' => [44100, 48000],
                'channels' => [1, 2],
                'bitrates' => [64000, 128000, 256000],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('device.device_uuid', $uuid);
        $this->assertNotEmpty($response->json('token'));

        $this->assertDatabaseHas('devices', [
            'device_uuid' => $uuid,
            'status' => 'ONLINE',
        ]);
    }

    public function test_re_registering_the_same_device_rotates_its_token(): void
    {
        $uuid = (string) Str::uuid();

        $first = $this->postJson('/api/devices/register', ['device_uuid' => $uuid]);
        $firstToken = $first->json('token');

        $second = $this->postJson('/api/devices/register', ['device_uuid' => $uuid]);
        $secondToken = $second->json('token');

        $this->assertNotEquals($firstToken, $secondToken);
        $this->assertSame(1, Device::query()->where('device_uuid', $uuid)->count());

        // The old token must no longer authenticate.
        $this->withHeader('Authorization', "Bearer {$firstToken}")
            ->postJson('/api/devices/heartbeat', [])
            ->assertUnauthorized();

        $this->withHeader('Authorization', "Bearer {$secondToken}")
            ->postJson('/api/devices/heartbeat', [])
            ->assertOk();
    }

    public function test_registration_requires_a_valid_uuid(): void
    {
        $this->postJson('/api/devices/register', ['device_uuid' => 'not-a-uuid'])
            ->assertUnprocessable();
    }
}
