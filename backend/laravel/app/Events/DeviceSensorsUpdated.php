<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsToAdminAndDevice;
use App\Models\Device;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Live sensor telemetry from a device (ambient light, accelerometer motion,
 * proximity). Broadcast-only — nothing is persisted, so the payload carries
 * everything the dashboard needs to render the reading as it arrives.
 */
class DeviceSensorsUpdated implements ShouldBroadcastNow
{
    use BroadcastsToAdminAndDevice, Dispatchable, SerializesModels;

    public int $deviceId;

    /**
     * @param  array<string, mixed>  $sensors
     */
    public function __construct(public Device $device, public array $sensors)
    {
        $this->deviceId = $device->id;
    }

    public function broadcastAs(): string
    {
        return 'DeviceSensorsUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'device_id' => $this->device->id,
            'sensors' => $this->sensors,
            'at' => now()->toIso8601String(),
        ];
    }
}
