<?php

namespace App\Services;

use App\Enums\DeviceStatus;
use App\Events\DeviceStatusChanged;
use App\Models\Device;

class DeviceHeartbeatService
{
    public function record(Device $device, ?string $reportedStatus = null): Device
    {
        $status = match (true) {
            $reportedStatus === DeviceStatus::RECORDING->value => DeviceStatus::RECORDING,
            $reportedStatus === DeviceStatus::ERROR->value => DeviceStatus::ERROR,
            default => DeviceStatus::ONLINE,
        };

        $device->forceFill([
            'last_seen_at' => now(),
            'status' => $status,
        ])->save();

        DeviceStatusChanged::dispatch($device);

        return $device;
    }

    /**
     * Sweep devices whose heartbeat has gone silent and mark them OFFLINE.
     * Intended to run on a scheduled command.
     */
    public function markStaleDevicesOffline(): int
    {
        $timeout = config('recorder.heartbeat_timeout_seconds');
        $count = 0;

        Device::query()
            ->where('status', '!=', DeviceStatus::OFFLINE)
            ->where('last_seen_at', '<', now()->subSeconds($timeout))
            ->each(function (Device $device) use (&$count) {
                $device->forceFill(['status' => DeviceStatus::OFFLINE])->save();
                DeviceStatusChanged::dispatch($device);
                $count++;
            });

        return $count;
    }
}
