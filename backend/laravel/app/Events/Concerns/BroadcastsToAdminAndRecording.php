<?php

namespace App\Events\Concerns;

use Illuminate\Broadcasting\PrivateChannel;

trait BroadcastsToAdminAndRecording
{
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.recordings'),
            new PrivateChannel("devices.{$this->deviceId}"),
            new PrivateChannel("recordings.{$this->recordingUuid}"),
        ];
    }
}
