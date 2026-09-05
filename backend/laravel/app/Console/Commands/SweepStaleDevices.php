<?php

namespace App\Console\Commands;

use App\Services\DeviceHeartbeatService;
use Illuminate\Console\Command;

class SweepStaleDevices extends Command
{
    protected $signature = 'devices:sweep-stale';

    protected $description = 'Mark devices OFFLINE whose heartbeat has exceeded the configured timeout.';

    public function handle(DeviceHeartbeatService $service): int
    {
        $count = $service->markStaleDevicesOffline();

        $this->info("Marked {$count} device(s) OFFLINE.");

        return self::SUCCESS;
    }
}
