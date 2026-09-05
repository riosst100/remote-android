<?php

namespace App\Console\Commands;

use App\Services\RecordingScheduleService;
use Illuminate\Console\Command;

class RunDueRecordingSchedules extends Command
{
    protected $signature = 'schedules:run-due';

    protected $description = 'Start recordings for any active schedule due this minute (Asia/Jakarta time).';

    public function handle(RecordingScheduleService $service): int
    {
        $started = $service->runDue();

        if ($started > 0) {
            $this->info("Triggered {$started} scheduled recording(s).");
        }

        return self::SUCCESS;
    }
}
