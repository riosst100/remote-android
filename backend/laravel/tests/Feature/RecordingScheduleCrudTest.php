<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\RecordingSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordingScheduleCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_create_a_schedule_for_a_device(): void
    {
        $this->actingAs(User::factory()->create());
        $device = Device::factory()->create();

        $response = $this->post(route('devices.schedules.store', $device), [
            'day_of_week' => 5,
            'time_of_day' => '03:30',
            'preset' => 'HIGH',
            'duration_minutes' => 30,
        ]);

        $response->assertRedirect(route('devices.show', $device));
        $this->assertDatabaseHas('recording_schedules', [
            'device_id' => $device->id,
            'day_of_week' => 5,
            'preset' => 'HIGH',
            'duration_minutes' => 30,
        ]);
    }

    public function test_creating_a_schedule_rejects_an_invalid_day_of_week(): void
    {
        $this->actingAs(User::factory()->create());
        $device = Device::factory()->create();

        $this->post(route('devices.schedules.store', $device), [
            'day_of_week' => 7,
            'time_of_day' => '03:30',
            'preset' => 'HIGH',
            'duration_minutes' => 30,
        ])->assertSessionHasErrors('day_of_week');
    }

    public function test_creating_a_schedule_accepts_lossless_preset(): void
    {
        $this->actingAs(User::factory()->create());
        $device = Device::factory()->create();

        $this->post(route('devices.schedules.store', $device), [
            'day_of_week' => 5,
            'time_of_day' => '03:30',
            'preset' => 'LOSSLESS',
            'duration_minutes' => 30,
        ])->assertSessionHasNoErrors();
    }

    public function test_an_admin_can_toggle_a_schedule_active_state(): void
    {
        $this->actingAs(User::factory()->create());
        $device = Device::factory()->create();
        $schedule = RecordingSchedule::factory()->create(['device_id' => $device->id, 'is_active' => true]);

        $this->post(route('devices.schedules.toggle', [$device, $schedule]))
            ->assertRedirect(route('devices.show', $device));

        $this->assertFalse($schedule->fresh()->is_active);
    }

    public function test_an_admin_can_delete_a_schedule(): void
    {
        $this->actingAs(User::factory()->create());
        $device = Device::factory()->create();
        $schedule = RecordingSchedule::factory()->create(['device_id' => $device->id]);

        $this->delete(route('devices.schedules.destroy', [$device, $schedule]))
            ->assertRedirect(route('devices.show', $device));

        $this->assertDatabaseMissing('recording_schedules', ['id' => $schedule->id]);
    }

    public function test_a_schedule_cannot_be_toggled_via_a_mismatched_device_in_the_url(): void
    {
        $this->actingAs(User::factory()->create());
        $deviceA = Device::factory()->create();
        $deviceB = Device::factory()->create();
        $schedule = RecordingSchedule::factory()->create(['device_id' => $deviceA->id]);

        $this->post(route('devices.schedules.toggle', [$deviceB, $schedule]))->assertNotFound();
    }

    public function test_schedule_routes_require_admin_authentication(): void
    {
        $device = Device::factory()->create();

        $this->post(route('devices.schedules.store', $device), [
            'day_of_week' => 5,
            'time_of_day' => '03:30',
            'preset' => 'HIGH',
            'duration_minutes' => 30,
        ])->assertRedirect(route('login'));
    }
}
