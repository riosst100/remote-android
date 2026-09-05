<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BroadcastAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The suite's default broadcaster is "null" so other tests' events
        // never attempt a real network call. Channel *authorization* is
        // pure HMAC signing with no network I/O, so it's safe (and is the
        // only way to meaningfully exercise routes/channels.php) to point
        // just these tests at the real "reverb" driver. Channel routes are
        // registered onto whichever driver instance is "default" at the
        // moment Broadcast::channel() runs, so routes/channels.php has to
        // be re-loaded after switching the default.
        config(['broadcasting.default' => 'reverb']);
        require base_path('routes/channels.php');
    }

    public function test_an_admin_can_authorize_the_admin_devices_channel(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');

        $response = $this->postJson('/api/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-admin.devices',
        ]);

        $response->assertOk();
        $this->assertArrayHasKey('auth', $response->json());
    }

    public function test_an_admin_can_authorize_any_devices_channel(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');
        $device = Device::factory()->create();

        $response = $this->postJson('/api/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => "private-devices.{$device->id}",
        ]);

        $response->assertOk();
    }

    public function test_a_device_can_authorize_its_own_channel_but_not_another_devices(): void
    {
        $device = Device::factory()->create();
        $otherDevice = Device::factory()->create();
        $token = $device->createToken('test')->plainTextToken;

        $ownChannel = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/devices/broadcasting/auth', [
                'socket_id' => '123.456',
                'channel_name' => "private-devices.{$device->id}",
            ]);
        $ownChannel->assertOk();

        $otherChannel = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/devices/broadcasting/auth', [
                'socket_id' => '123.456',
                'channel_name' => "private-devices.{$otherDevice->id}",
            ]);
        $otherChannel->assertForbidden();
    }

    public function test_an_unauthenticated_request_cannot_authorize_any_channel(): void
    {
        $this->postJson('/api/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-admin.devices',
        ])->assertUnauthorized();
    }
}
