<?php

namespace Tests\Feature;

use App\Enums\CommandStatus;
use App\Enums\DeviceStatus;
use App\Events\FlashCommandRequested;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DeviceFlashTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');

        return $user;
    }

    public function test_admin_can_turn_the_flash_on(): void
    {
        Event::fake([FlashCommandRequested::class]);
        $this->actingAsAdmin();
        $device = Device::factory()->create(['status' => DeviceStatus::ONLINE]);

        $response = $this->postJson("/api/devices/{$device->id}/flash", ['on' => true]);

        $response->assertStatus(202);
        $response->assertJsonPath('data.command', 'FLASH_ON');

        $this->assertDatabaseHas('device_commands', [
            'device_id' => $device->id,
            'command' => 'FLASH_ON',
            'recording_id' => null,
            'status' => CommandStatus::SENT->value,
        ]);

        Event::assertDispatched(FlashCommandRequested::class);
    }

    public function test_admin_can_turn_the_flash_off(): void
    {
        $this->actingAsAdmin();
        $device = Device::factory()->create(['status' => DeviceStatus::ONLINE]);

        $response = $this->postJson("/api/devices/{$device->id}/flash", ['on' => false]);

        $response->assertStatus(202);
        $response->assertJsonPath('data.command', 'FLASH_OFF');
    }

    public function test_flash_cannot_be_toggled_on_an_offline_device(): void
    {
        $this->actingAsAdmin();
        $device = Device::factory()->create(['status' => DeviceStatus::OFFLINE]);

        $response = $this->postJson("/api/devices/{$device->id}/flash", ['on' => true]);

        $response->assertStatus(409);
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_flash_endpoint_requires_the_on_field(): void
    {
        $this->actingAsAdmin();
        $device = Device::factory()->create(['status' => DeviceStatus::ONLINE]);

        $this->postJson("/api/devices/{$device->id}/flash", [])
            ->assertStatus(422);
    }

    public function test_flash_endpoint_requires_admin_authentication(): void
    {
        $device = Device::factory()->create(['status' => DeviceStatus::ONLINE]);

        $this->postJson("/api/devices/{$device->id}/flash", ['on' => true])
            ->assertUnauthorized();
    }

    public function test_device_can_ack_a_flash_command_as_applied(): void
    {
        $device = Device::factory()->create(['status' => DeviceStatus::ONLINE]);
        $token = $device->createToken('test')->plainTextToken;

        $command = DeviceCommand::factory()->create([
            'device_id' => $device->id,
            'recording_id' => null,
            'command' => 'FLASH_ON',
            'status' => CommandStatus::SENT,
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/commands/{$command->command_id}/ack", ['event' => 'flash_applied'])
            ->assertOk();

        $this->assertSame(CommandStatus::COMPLETED, $command->fresh()->status);
    }
}
