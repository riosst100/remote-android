<?php

namespace App\Jobs;

use App\Models\Device;
use App\Services\SensorRuleEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Backstop re-check for sensor rules with a dwell window (when.for_seconds).
 *
 * The device reports sensors only when they change, so once a condition is
 * met and then holds steady the server may receive nothing further to confirm
 * the dwell has elapsed. When a dwell window opens, the engine schedules this
 * job for the end of that window; it re-runs the rules against the last
 * reading the device sent, so a condition that is still holding fires and one
 * that has since recovered does not.
 */
class EvaluateDeviceSensorRules implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $deviceId) {}

    public function handle(SensorRuleEngine $engine): void
    {
        $device = Device::query()->find($this->deviceId);
        if (! $device) {
            return;
        }

        $sensors = Cache::get("sensor-rules:{$this->deviceId}:last");
        if (is_array($sensors)) {
            $engine->evaluate($device, $sensors);
        }
    }
}
