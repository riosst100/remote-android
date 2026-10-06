<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsToAdminAndDevice;
use App\Models\DeviceCommand;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pushes a FLASH_ON / FLASH_OFF command to the device over its private
 * channel. Unlike the recording commands there is no Recording backing it,
 * so it broadcasts on the device channel only (BroadcastsToAdminAndDevice).
 */
class FlashCommandRequested implements ShouldBroadcastNow
{
    use BroadcastsToAdminAndDevice, Dispatchable, SerializesModels;

    public int $deviceId;

    public function __construct(public DeviceCommand $command)
    {
        $this->deviceId = $command->device_id;
    }

    public function broadcastAs(): string
    {
        return 'FlashCommandRequested';
    }

    public function broadcastWith(): array
    {
        return [
            'command_id' => $this->command->command_id,
            // FLASH_ON or FLASH_OFF — the agent switches on this value.
            'command' => $this->command->command->value,
            // The device's UUID, matching the START/STOP command contract
            // the Android agent already parses (Command.deviceId).
            'device_id' => $this->command->device->device_uuid,
            'admin_device_id' => $this->deviceId,
            'timestamp' => $this->command->created_at->toIso8601String(),
        ];
    }
}
