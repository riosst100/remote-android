<?php

namespace App\Events\Concerns;

use Illuminate\Broadcasting\PrivateChannel;

trait BroadcastsToAdminAndDevice
{
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.devices'),
            new PrivateChannel("devices.{$this->deviceId}"),
        ];
    }
}
