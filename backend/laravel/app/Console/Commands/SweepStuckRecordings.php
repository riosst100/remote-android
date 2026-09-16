<?php

namespace App\Console\Commands;

use App\Services\RecordingLifecycleService;
use Illuminate\Console\Command;

class SweepStuckRecordings extends Command
{
    protected $signature = 'recordings:sweep-stuck';

    protected $description = 'Fail recordings stuck in STOPPING/PROCESSING with no progress past the configured timeout.';

    public function handle(RecordingLifecycleService $service): int
    {
        $count = $service->failStuckRecordings();

        if ($count > 0) {
            $this->info("Failed {$count} stuck recording(s).");
        }

        return self::SUCCESS;
    }
}
