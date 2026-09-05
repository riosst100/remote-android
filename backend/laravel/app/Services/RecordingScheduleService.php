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

    public function __construct(private readonly RecordingLifecycleService $lifecycle) {}

    /**
     * Finds every active schedule whose (day_of_week, time_of_day) matches
     * the current minute in Asia/Jakarta and starts a recording for each,
     * stopping it again after its configured duration via a queued job.
     *
     * Runs every minute from the scheduler (routes/console.php). Matching
     * on the current minute rather than "due since last run" keeps this
     * simple and correct as long as the scheduler itself doesn't skip a
     * whole minute — an acceptable trade-off for a feature whose whole
     * point is "roughly this time each week," not sub-minute precision.
     */
    public function runDue(): int
    {
        $now = Carbon::now(self::SCHEDULE_TIMEZONE);
        $today = $now->dayOfWeek;
        $currentMinute = $now->format('H:i');

        // Filtered in PHP rather than via a DB-specific time-formatting
        // function (e.g. Postgres's to_char) so this stays portable to the
        // SQLite connection the test suite runs against.
        $due = RecordingSchedule::query()
            ->where('is_active', true)
            ->where('day_of_week', $today)
            ->with('device')
            ->get()
            ->filter(fn (RecordingSchedule $schedule) => substr((string) $schedule->time_of_day, 0, 5) === $currentMinute);

        $started = 0;

        foreach ($due as $schedule) {
            if ($this->alreadyRanThisMinute($schedule, $now)) {
                continue;
            }

            if ($this->trigger($schedule, $now)) {
                $started++;
            }
        }

        return $started;
    }

    private function alreadyRanThisMinute(RecordingSchedule $schedule, Carbon $now): bool
    {
        return $schedule->last_run_at !== null
            && $schedule->last_run_at->copy()->setTimezone(self::SCHEDULE_TIMEZONE)->format('Y-m-d H:i') === $now->format('Y-m-d H:i');
    }

    /**
     * @return bool true if a recording was actually started.
     */
    private function trigger(RecordingSchedule $schedule, Carbon $now): bool
    {
        return DB::transaction(function () use ($schedule, $now) {
            $locked = RecordingSchedule::query()->lockForUpdate()->find($schedule->id);

            if ($locked === null || $this->alreadyRanThisMinute($locked, $now)) {
                return false;
            }

            // Marked as run regardless of outcome below — an offline device
            // shouldn't be retried every minute for the rest of its
            // scheduled slot, only reconsidered the next time this exact
            // schedule comes due (next week).
            $locked->forceFill(['last_run_at' => now()])->save();

            $device = $locked->device;

            if ($device->status === DeviceStatus::OFFLINE) {
                Log::warning('Scheduled recording skipped: device offline', [
                    'schedule_id' => $locked->id,
                    'device_id' => $device->id,
                ]);

                return false;
            }

            $recording = $this->lifecycle->start($device, $locked->preset);

            StopScheduledRecording::dispatch($recording->id)
                ->delay(now()->addMinutes($locked->duration_minutes));

            return true;
        });
    }
}
