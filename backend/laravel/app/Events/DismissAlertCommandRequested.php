<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsToAdminAndDevice;
use App\Models\DeviceCommand;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pushes a DISMISS_ALERT command to the device so the admin can close a
 * currently-showing alert popup from the dashboard. Mirror of
 * AlertCommandRequested; carries no payload beyond the command id.
 */
class DismissAlertCommandRequested implements ShouldBroadcastNow
{
    use BroadcastsToAdminAndDevice, Dispatchable, SerializesModels;

    public int $deviceId;

    public function __construct(public DeviceCommand $command)
    {
        $this->deviceId = $command->device_id;
    }

    public function broadcastAs(): string
    {
        return 'DismissAlertCommandRequested';
    }

    public function broadcastWith(): array
    {
        return [
            'command_id' => $this->command->command_id,
            'command' => $this->command->command->value,
            'admin_device_id' => $this->deviceId,
            'timestamp' => $this->command->created_at->toIso8601String(),
        ];
    }
}
