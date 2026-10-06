<?php

namespace Tests\Feature;

use App\Enums\CommandStatus;
use App\Enums\DeviceStatus;
use App\Events\AlertCommandRequested;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DeviceAlertTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');

        return $user;
    }

    public function test_admin_can_send_an_alert(): void
    {
        Event::fake([AlertCommandRequested::class]);
        $this->actingAsAdmin();
        $device = Device::factory()->create(['status' => DeviceStatus::ONLINE]);

        $response = $this->postJson("/api/devices/{$device->id}/alert", [
            'title' => 'Attention',
            'message' => 'Please check this device.',
            'volume' => 70,
            'brightness' => 40,
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('data.command', 'SHOW_ALERT');

        $command = DeviceCommand::query()->where('device_id', $device->id)->firstOrFail();
        $this->assertSame('SHOW_ALERT', $command->command->value);
        $this->assertSame('Attention', $command->payload['title']);
        $this->assertSame('Please check this device.', $command->payload['message']);
        $this->assertSame(70, $command->payload['volume']);
        $this->assertSame(40, $command->payload['brightness']);
        $this->assertNull($command->recording_id);

        Event::assertDispatched(AlertCommandRequested::class);
    }

    public function test_sending_an_alert_remembers_its_settings_on_the_device(): void
    {
        $this->actingAsAdmin();
        $device = Device::factory()->create(['status' => DeviceStatus::ONLINE]);

        $this->postJson("/api/devices/{$device->id}/alert", [
            'title' => 'Fire drill',
            'message' => 'Evacuate now',
            'volume' => 80,
            'brightness' => 60,
            'button_label' => 'OK, Paham',
        ])->assertStatus(202);

        $device->refresh();
        $this->assertSame('Fire drill', $device->alert_defaults['title']);
        $this->assertSame('Evacuate now', $device->alert_defaults['message']);
        $this->assertSame(80, $device->alert_defaults['volume']);
        $this->assertSame(60, $device->alert_defaults['brightness']);
        $this->assertSame('OK, Paham', $device->alert_defaults['button_label']);

        $command = DeviceCommand::query()->where('device_id', $device->id)->firstOrFail();
        $this->assertSame('OK, Paham', $command->payload['button_label']);
    }

    public function test_alert_requires_title_and_message(): void
    {
        $this->actingAsAdmin();
        $device = Device::factory()->create(['status' => DeviceStatus::ONLINE]);

        $this->postJson("/api/devices/{$device->id}/alert", ['title' => 'Hi'])
            ->assertStatus(422);
    }

    public function test_alert_volume_is_optional_and_defaults_to_100(): void
    {
        $this->actingAsAdmin();
        $device = Device::factory()->create(['status' => DeviceStatus::ONLINE]);

        $this->postJson("/api/devices/{$device->id}/alert", [
            'title' => 'Attention',
            'message' => 'Hello',
        ])->assertStatus(202);

        $command = DeviceCommand::query()->where('device_id', $device->id)->firstOrFail();
        $this->assertSame(100, $command->payload['volume']);
        $this->assertSame(100, $command->payload['brightness']);
    }

    public function test_alert_volume_and_brightness_must_be_within_range(): void
    {
        $this->actingAsAdmin();
        $device = Device::factory()->create(['status' => DeviceStatus::ONLINE]);

        $this->postJson("/api/devices/{$device->id}/alert", [
            'title' => 'Attention',
            'message' => 'Hello',
            'volume' => 150,
        ])->assertStatus(422);

        $this->postJson("/api/devices/{$device->id}/alert", [
            'title' => 'Attention',
            'message' => 'Hello',
            'brightness' => -5,
        ])->assertStatus(422);
    }

    public function test_alert_cannot_be_sent_to_an_offline_device(): void
    {
        $this->actingAsAdmin();
        $device = Device::factory()->create(['status' => DeviceStatus::OFFLINE]);

        $this->postJson("/api/devices/{$device->id}/alert", [
            'title' => 'Attention',
            'message' => 'Hello',
        ])->assertStatus(409);

        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_alert_endpoint_requires_admin_authentication(): void
    {
        $device = Device::factory()->create(['status' => DeviceStatus::ONLINE]);

        $this->postJson("/api/devices/{$device->id}/alert", [
            'title' => 'Attention',
            'message' => 'Hello',
        ])->assertUnauthorized();
    }

    public function test_device_can_ack_an_alert_as_shown(): void
    {
        $device = Device::factory()->create(['status' => DeviceStatus::ONLINE]);
        $token = $device->createToken('test')->plainTextToken;

        $command = DeviceCommand::factory()->create([
            'device_id' => $device->id,
            'recording_id' => null,
            'command' => 'SHOW_ALERT',
            'status' => CommandStatus::SENT,
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/commands/{$command->command_id}/ack", ['event' => 'alert_shown'])
            ->assertOk();

        $this->assertSame(CommandStatus::COMPLETED, $command->fresh()->status);
    }
}
