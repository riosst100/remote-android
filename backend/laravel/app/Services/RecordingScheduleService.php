<?php

namespace App\Services;

use App\Enums\DeviceStatus;
use App\Jobs\StopScheduledRecording;
use App\Models\RecordingSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecordingScheduleService
{
    /**
     * Wall-clock times on schedules are always Asia/Jakarta (UTC+7),
     * independent of the server's own timezone (UTC) — this is what an
     * admin typing "Friday 03:30" actually means.
     */
    public const SCHEDULE_TIMEZONE = 'Asia/Jakarta';

    /**
     * How long past the scheduled minute we keep retrying a schedule whose
     * device was offline, e.g. because the phone had just rebooted and
     * hadn't reconnected yet. Beyond this window we give up until the
     * schedule's next weekly occurrence. Set comfortably above the
     * Android agent's own periodic heartbeat interval (15 minutes, see
     * HeartbeatWorker) plus boot time, so a reboot right at the scheduled
     * minute doesn't race the retry window closed before the device is
     * back online.
     */
    public const RETRY_WINDOW_MINUTES = 30;

    public function __construct(private readonly RecordingLifecycleService $lifecycle) {}

    /**
     * Finds every active schedule that's either due this minute or still
     * within its retry window from a due minute it missed (device offline),
     * and starts a recording for each, stopping it again after its
     * configured duration via a queued job.
     *
     * Runs every minute from the scheduler (routes/console.php).
     */
    public function runDue(): int
    {
        $now = Carbon::now(self::SCHEDULE_TIMEZONE);
        $today = $now->dayOfWeek;
        $currentMinute = $now->format('H:i');

        // Filtered in PHP rather than via a DB-specific time-formatting
        // function (e.g. Postgres's to_char) so this stays portable to the
        // SQLite connection the test suite runs against.
        $candidates = RecordingSchedule::query()
            ->where('is_active', true)
            ->where('day_of_week', $today)
            ->with('device')
            ->get()
            ->filter(function (RecordingSchedule $schedule) use ($currentMinute, $now) {
                if ($this->settledForToday($schedule, $now)) {
                    return false;
                }

                if (substr((string) $schedule->time_of_day, 0, 5) === $currentMinute) {
                    return true;
                }

                // Past its due minute: keep retrying only while inside the
                // window since the first miss (last_attempt_at). A stale
                // attempt from a previous week is always older than the
                // window, so this naturally excludes it without a separate
                // same-day check.
                return $schedule->last_attempt_at !== null
                    && $schedule->last_attempt_at->copy()->setTimezone(self::SCHEDULE_TIMEZONE)
                        ->gt($now->copy()->subMinutes(self::RETRY_WINDOW_MINUTES));
            });

        $started = 0;

        foreach ($candidates as $schedule) {
            if ($this->trigger($schedule, $now)) {
                $started++;
            }
        }

        return $started;
    }

    /**
     * True once a schedule no longer needs (re)consideration today: it
     * already started successfully, or its retry window has fully lapsed
     * without ever starting.
     */
    private function settledForToday(RecordingSchedule $schedule, Carbon $now): bool
    {
        if ($schedule->last_run_at !== null
            && $schedule->last_run_at->copy()->setTimezone(self::SCHEDULE_TIMEZONE)->format('Y-m-d') === $now->format('Y-m-d')) {
            return true;
        }

        if ($schedule->last_attempt_at !== null) {
            $lastAttempt = $schedule->last_attempt_at->copy()->setTimezone(self::SCHEDULE_TIMEZONE);

            // Only a same-day attempt can settle (or extend the retry
            // window for) today's occurrence — an attempt from a previous
            // week must not carry over and block this week's occurrence.
            if ($lastAttempt->format('Y-m-d') === $now->format('Y-m-d')
                && $lastAttempt->lte($now->copy()->subMinutes(self::RETRY_WINDOW_MINUTES))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return bool true if a recording was actually started.
     */
    private function trigger(RecordingSchedule $schedule, Carbon $now): bool
    {
        return DB::transaction(function () use ($schedule, $now) {
            $locked = RecordingSchedule::query()->lockForUpdate()->find($schedule->id);

            if ($locked === null || $this->settledForToday($locked, $now)) {
                return false;
            }

            $device = $locked->device;

            if ($device->status === DeviceStatus::OFFLINE) {
                // Record the first miss so settledForToday() knows when the
                // retry window for this occurrence started, but leave
                // last_run_at null so we keep retrying each minute.
                if ($locked->last_attempt_at === null) {
                    $locked->forceFill(['last_attempt_at' => now()])->save();
                }

                Log::warning('Scheduled recording skipped: device offline', [
                    'schedule_id' => $locked->id,
                    'device_id' => $device->id,
                ]);

                return false;
            }

            $locked->forceFill(['last_run_at' => now(), 'last_attempt_at' => now()])->save();

            $recording = $this->lifecycle->start($device, $locked->preset);

            StopScheduledRecording::dispatch($recording->id)
                ->delay(now()->addMinutes($locked->duration_minutes));

            return true;
        });
    }
}
