<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsToAdminAndDevice;
use App\Models\Device;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeviceStatusChanged implements ShouldBroadcast
{
    use BroadcastsToAdminAndDevice, Dispatchable, SerializesModels;

    public int $deviceId;

    public function __construct(public Device $device)
    {
        $this->deviceId = $device->id;
    }

    public function broadcastAs(): string
    {
        return 'DeviceStatusChanged';
    }

    public function broadcastWith(): array
    {
        return [
            'device_id' => $this->device->id,
            'device_uuid' => $this->device->device_uuid,
            'status' => $this->device->status->value,
            'last_seen_at' => optional($this->device->last_seen_at)->toIso8601String(),
        ];
    }
}
