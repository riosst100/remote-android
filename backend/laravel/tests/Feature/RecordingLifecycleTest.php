<?php

namespace Tests\Feature;

use App\Enums\CommandStatus;
use App\Enums\DeviceStatus;
use App\Enums\RecordingStatus;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');

        return $user;
    }

    public function test_admin_can_start_a_recording_on_an_online_device(): void
    {
        $this->actingAsAdmin();
        $device = Device::factory()->create(['status' => DeviceStatus::ONLINE]);

        $response = $this->postJson('/api/recordings/start', [
            'device_id' => $device->id,
            'preset' => 'HIGH',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'STARTING');
        $response->assertJsonPath('data.preset', 'HIGH');

        $this->assertDatabaseHas('device_commands', [
            'device_id' => $device->id,
            'command' => 'START_RECORDING',
            'status' => CommandStatus::SENT->value,
        ]);
    }

    public function test_starting_a_recording_twice_for_the_same_device_is_idempotent(): void
    {
        $this->actingAsAdmin();
        $device = Device::factory()->create(['status' => DeviceStatus::ONLINE]);

        $first = $this->postJson('/api/recordings/start', ['device_id' => $device->id, 'preset' => 'HIGH']);
        $second = $this->postJson('/api/recordings/start', ['device_id' => $device->id, 'preset' => 'HIGH']);

        $this->assertSame($first->json('data.uuid'), $second->json('data.uuid'));
        $this->assertSame(1, $device->recordings()->count());
        $this->assertSame(1, DeviceCommand::query()->where('command', 'START_RECORDING')->count());
    }

    public function test_cannot_start_a_recording_on_an_offline_device(): void
    {
        $this->actingAsAdmin();
        $device = Device::factory()->create(['status' => DeviceStatus::OFFLINE]);

        $this->postJson('/api/recordings/start', ['device_id' => $device->id, 'preset' => 'HIGH'])
            ->assertStatus(409);
    }

    public function test_device_can_acknowledge_start_with_actual_configuration(): void
    {
        $this->actingAsAdmin();
        $device = Device::factory()->create(['status' => DeviceStatus::ONLINE]);
        $token = $device->createToken('test')->plainTextToken;

        $start = $this->postJson('/api/recordings/start', ['device_id' => $device->id, 'preset' => 'HIGH']);
        $recordingUuid = $start->json('data.uuid');
        $commandId = DeviceCommand::query()->where('recording_id', function ($q) use ($recordingUuid) {
            $q->select('id')->from('recordings')->where('uuid', $recordingUuid);
        })->first()->command_id;

        $ack = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/commands/{$commandId}/ack", [
                'event' => 'recording_started',
                'configuration' => [
                    'encoder' => 'aac',
                    'sample_rate' => 44100,
                    'bitrate' => 128000,
                    'channels' => 1,
                ],
            ]);

        $ack->assertOk();

        $this->assertDatabaseHas('recordings', [
            'uuid' => $recordingUuid,
            'status' => RecordingStatus::RECORDING->value,
            'sample_rate' => 44100,
            'bitrate' => 128000,
        ]);

        $device->refresh();
        $this->assertSame(DeviceStatus::RECORDING, $device->status);
    }

    public function test_acknowledging_stop_records_a_non_negative_integer_duration(): void
    {
        $this->actingAsAdmin();
        $device = Device::factory()->create(['status' => DeviceStatus::ONLINE]);
        $token = $device->createToken('test')->plainTextToken;

        $start = $this->postJson('/api/recordings/start', ['device_id' => $device->id, 'preset' => 'HIGH']);
        $recordingUuid = $start->json('data.uuid');
        $startCommandId = DeviceCommand::query()->where('command', 'START_RECORDING')->first()->command_id;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/commands/{$startCommandId}/ack", ['event' => 'recording_started']);

        $this->postJson("/api/recordings/{$recordingUuid}/stop");
        $stopCommandId = DeviceCommand::query()->where('command', 'STOP_RECORDING')->first()->command_id;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/commands/{$stopCommandId}/ack", ['event' => 'recording_stopped'])
            ->assertOk();

        $duration = \App\Models\Recording::query()->where('uuid', $recordingUuid)->value('duration');
        $this->assertIsInt($duration);
        $this->assertGreaterThanOrEqual(0, $duration);
    }

    public function test_stopping_an_already_stopping_recording_is_idempotent(): void
    {
        $this->actingAsAdmin();
        $device = Device::factory()->create(['status' => DeviceStatus::ONLINE]);

        $start = $this->postJson('/api/recordings/start', ['device_id' => $device->id, 'preset' => 'HIGH']);
        $recordingUuid = $start->json('data.uuid');

        $first = $this->postJson("/api/recordings/{$recordingUuid}/stop");
        $second = $this->postJson("/api/recordings/{$recordingUuid}/stop");

        $first->assertOk();
        $second->assertOk();
        $this->assertSame(1, DeviceCommand::query()->where('command', 'STOP_RECORDING')->count());
    }

    public function test_recording_endpoints_require_admin_authentication(): void
    {
        $device = Device::factory()->create();

        $this->postJson('/api/recordings/start', ['device_id' => $device->id, 'preset' => 'HIGH'])
            ->assertUnauthorized();
    }
}
