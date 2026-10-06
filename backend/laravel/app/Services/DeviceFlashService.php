<?php

namespace App\Services;

use App\Enums\CommandStatus;
use App\Enums\CommandType;
use App\Enums\DeviceStatus;
use App\Events\FlashCommandRequested;
use App\Exceptions\DeviceUnavailableException;
use App\Models\Device;
use App\Models\DeviceCommand;
use Illuminate\Support\Str;

class DeviceFlashService
{
    /**
     * Dispatch a FLASH_ON / FLASH_OFF command to the device. Fire-and-forget:
     * unlike recording, the flashlight has no multi-step lifecycle to track —
     * the command is sent, the device toggles its torch and acks. A command
     * is created per request (no dedupe) so a user can freely toggle on/off.
     */
    public function setFlash(Device $device, bool $on): DeviceCommand
    {
        if ($device->status === DeviceStatus::OFFLINE) {
            throw new DeviceUnavailableException('Device is offline and cannot toggle the flashlight.');
        }

        $command = DeviceCommand::query()->create([
            'device_id' => $device->id,
            'recording_id' => null,
            'command_id' => (string) Str::uuid(),
            'command' => $on ? CommandType::FLASH_ON : CommandType::FLASH_OFF,
            'payload' => [],
            'status' => CommandStatus::SENT,
            'sent_at' => now(),
        ]);

        $command->load('device');
        FlashCommandRequested::dispatch($command);

        return $command;
    }
}
