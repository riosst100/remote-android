<?php

namespace Tests\Feature;

use App\Enums\DeviceStatus;
use App\Enums\RecordingStatus;
use App\Jobs\StopScheduledRecording;
use App\Models\Device;
use App\Models\Recording;
use App\Models\RecordingSchedule;
use App\Services\RecordingScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class RecordingScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_due_schedule_starts_a_recording(): void
    {
        Bus::fake();

        // Friday 2026-01-09 03:30 Asia/Jakarta.
        Carbon::setTestNow(Carbon::parse('2026-01-09 03:30:00', 'Asia/Jakarta'));

        $device = Device::factory()->create(['status' => DeviceStatus::ONLINE]);
        RecordingSchedule::factory()->create([
            'device_id' => $device->id,
            'day_of_week' => 5, // Friday
            'time_of_day' => '03:30:00',
        ]);

        $started = app(RecordingScheduleService::class)->runDue();

        $this->assertSame(1, $started);
        $this->assertSame(1, Recording::query()->where('device_id', $device->id)->count());
        Bus::assertDispatched(StopScheduledRecording::class);

        Carbon::setTestNow();
    }

    public function test_a_schedule_does_not_fire_on_the_wrong_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-10 03:30:00', 'Asia/Jakarta')); // Saturday

        $device = Device::factory()->create(['status' => DeviceStatus::ONLINE]);
        RecordingSchedule::factory()->create([
            'device_id' => $device->id,
            'day_of_week' => 5, // Friday
            'time_of_day' => '03:30:00',
        ]);

        $started = app(RecordingScheduleService::class)->runDue();

        $this->assertSame(0, $started);
        $this->assertSame(0, Recording::query()->count());

        Carbon::setTestNow();
    }

    public function test_a_schedule_does_not_fire_at_the_wrong_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-09 03:31:00', 'Asia/Jakarta'));

        $device = Device::factory()->create(['status' => DeviceStatus::ONLINE]);
        RecordingSchedule::factory()->create([
            'device_id' => $device->id,
            'day_of_week' => 5,
            'time_of_day' => '03:30:00',
        ]);

        $started = app(RecordingScheduleService::class)->runDue();

        $this->assertSame(0, $started);

        Carbon::setTestNow();
    }

    public function test_an_inactive_schedule_does_not_fire(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-09 03:30:00', 'Asia/Jakarta'));

        $device = Device::factory()->create(['status' => DeviceStatus::ONLINE]);
        RecordingSchedule::factory()->create([
            'device_id' => $device->id,
            'day_of_week' => 5,
            'time_of_day' => '03:30:00',
            'is_active' => false,
        ]);

        $started = app(RecordingScheduleService::class)->runDue();

        $this->assertSame(0, $started);

        Carbon::setTestNow();
    }

    public function test_a_schedule_does_not_fire_twice_for_the_same_minute(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-09 03:30:00', 'Asia/Jakarta'));

        $device = Device::factory()->create(['status' => DeviceStatus::ONLINE]);
        RecordingSchedule::factory()->create([
            'device_id' => $device->id,
            'day_of_week' => 5,
            'time_of_day' => '03:30:00',
        ]);

        $service = app(RecordingScheduleService::class);
        $first = $service->runDue();
        $second = $service->runDue();

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        $this->assertSame(1, Recording::query()->count());

        Carbon::setTestNow();
    }

    public function test_a_schedule_for_an_offline_device_is_skipped_without_erroring(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-09 03:30:00', 'Asia/Jakarta'));

        $device = Device::factory()->create(['status' => DeviceStatus::OFFLINE]);
        RecordingSchedule::factory()->create([
            'device_id' => $device->id,
            'day_of_week' => 5,
            'time_of_day' => '03:30:00',
        ]);

        $started = app(RecordingScheduleService::class)->runDue();

        $this->assertSame(0, $started);
        $this->assertSame(0, Recording::query()->count());

        $schedule = RecordingSchedule::query()->first();
        $this->assertNull($schedule->last_run_at, 'last_run_at should stay null since the recording never actually started.');
        $this->assertNotNull($schedule->last_attempt_at, 'last_attempt_at should be marked so we know when the retry window started.');

        Carbon::setTestNow();
    }

    public function test_a_schedule_retries_within_the_window_once_the_device_comes_back_online(): void
    {
        // The device reboots right as its schedule comes due, then
        // reconnects a few minutes later — this must still start.
        Carbon::setTestNow(Carbon::parse('2026-01-09 03:30:00', 'Asia/Jakarta'));

        $device = Device::factory()->create(['status' => DeviceStatus::OFFLINE]);
        RecordingSchedule::factory()->create([
            'device_id' => $device->id,
            'day_of_week' => 5,
            'time_of_day' => '03:30:00',
        ]);

        $service = app(RecordingScheduleService::class);
        $this->assertSame(0, $service->runDue());

        Carbon::setTestNow(Carbon::parse('2026-01-09 03:34:00', 'Asia/Jakarta'));
        $device->forceFill(['status' => DeviceStatus::ONLINE])->save();

        $started = $service->runDue();

        $this->assertSame(1, $started);
        $this->assertSame(1, Recording::query()->where('device_id', $device->id)->count());

        Carbon::setTestNow();
    }

    public function test_a_schedule_stops_retrying_once_the_window_lapses(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-09 03:30:00', 'Asia/Jakarta'));

        $device = Device::factory()->create(['status' => DeviceStatus::OFFLINE]);
        RecordingSchedule::factory()->create([
            'device_id' => $device->id,
            'day_of_week' => 5,
            'time_of_day' => '03:30:00',
        ]);

        $service = app(RecordingScheduleService::class);
        $this->assertSame(0, $service->runDue());

        // Comes back online after the retry window has lapsed.
        Carbon::setTestNow(Carbon::parse('2026-01-09 04:01:00', 'Asia/Jakarta'));
        $device->forceFill(['status' => DeviceStatus::ONLINE])->save();

        $started = $service->runDue();

        $this->assertSame(0, $started, 'A device coming back online after the retry window should not retroactively start.');
        $this->assertSame(0, Recording::query()->count());

        Carbon::setTestNow();
    }

    public function test_the_stop_job_stops_the_recording_after_its_duration(): void
    {
        $device = Device::factory()->create(['status' => DeviceStatus::RECORDING]);
        $recording = Recording::factory()->create([
            'device_id' => $device->id,
            'status' => RecordingStatus::RECORDING,
            'started_at' => now()->subMinutes(30),
        ]);

        (new StopScheduledRecording($recording->id))->handle(app(\App\Services\RecordingLifecycleService::class));

        // stop() dispatches a STOP_RECORDING command and moves the
        // recording to STOPPING; it only becomes PROCESSING once the
        // device acknowledges the stop (RecordingLifecycleService::
        // acknowledgeStopped), which is outside this job's job.
        $recording->refresh();
        $this->assertSame(RecordingStatus::STOPPING, $recording->status);
        $this->assertDatabaseHas('device_commands', [
            'recording_id' => $recording->id,
            'command' => 'STOP_RECORDING',
        ]);
    }
}
