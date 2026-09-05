<?php

namespace Tests\Feature;

use App\Enums\DeviceStatus;
use App\Enums\RecordingStatus;
use App\Models\Device;
use App\Models\Recording;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceErrorReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_device_can_report_a_permission_error(): void
    {
        $device = Device::factory()->create(['status' => DeviceStatus::ONLINE]);
        $token = $device->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/devices/error', [
                'error_code' => 'MIC_PERMISSION_DENIED',
                'message' => 'User denied RECORD_AUDIO.',
            ]);

        $response->assertOk();

        $device->refresh();
        $this->assertSame(DeviceStatus::ERROR, $device->status);
    }

    public function test_reporting_an_error_for_a_recording_marks_it_failed(): void
    {
        $device = Device::factory()->create();
        $token = $device->createToken('test')->plainTextToken;
        $recording = Recording::factory()->create(['device_id' => $device->id, 'status' => RecordingStatus::RECORDING]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/devices/error', [
                'error_code' => 'STORAGE_ERROR',
                'message' => 'Disk full.',
                'recording_id' => $recording->uuid,
            ])
            ->assertOk();

        $recording->refresh();
        $this->assertSame(RecordingStatus::FAILED, $recording->status);
        $this->assertStringContainsString('Disk full.', $recording->error_message);
    }

    public function test_rejects_an_unrecognized_error_code(): void
    {
        $device = Device::factory()->create();
        $token = $device->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/devices/error', ['error_code' => 'NOT_A_REAL_CODE'])
            ->assertUnprocessable();
    }
}
