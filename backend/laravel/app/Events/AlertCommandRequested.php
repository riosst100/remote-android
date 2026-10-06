<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsToAdminAndDevice;
use App\Models\DeviceCommand;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pushes a SHOW_ALERT command (title + message) to the device over its
 * private channel. Like the flash command, there is no Recording behind
 * it, so it broadcasts on the device channel only.
 */
class AlertCommandRequested implements ShouldBroadcastNow
{
    use BroadcastsToAdminAndDevice, Dispatchable, SerializesModels;

    public int $deviceId;

    public function __construct(public DeviceCommand $command)
    {
        $this->deviceId = $command->device_id;
    }

    public function broadcastAs(): string
    {
        return 'AlertCommandRequested';
    }

    public function broadcastWith(): array
    {
        return [
            'command_id' => $this->command->command_id,
            'command' => $this->command->command->value,
            'device_id' => $this->command->device->device_uuid,
            'admin_device_id' => $this->deviceId,
            'title' => $this->command->payload['title'] ?? null,
            'message' => $this->command->payload['message'] ?? null,
            'volume' => $this->command->payload['volume'] ?? 100,
            'brightness' => $this->command->payload['brightness'] ?? 100,
            'button_label' => $this->command->payload['button_label'] ?? 'Dismiss',
            'timestamp' => $this->command->created_at->toIso8601String(),
        ];
    }
}
