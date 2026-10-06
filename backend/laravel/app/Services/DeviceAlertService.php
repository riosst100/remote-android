<?php

namespace App\Services;

use App\Enums\CommandStatus;
use App\Enums\CommandType;
use App\Enums\DeviceStatus;
use App\Events\AlertCommandRequested;
use App\Exceptions\DeviceUnavailableException;
use App\Models\Device;
use App\Models\DeviceCommand;
use Illuminate\Support\Str;

class DeviceAlertService
{
    /**
     * Dispatch a SHOW_ALERT command carrying a title + message. The device
     * shows a full-screen alert with an alarm sound until the user dismisses
     * it. Fire-and-forget, same shape as the flash command.
     */
    public function sendAlert(Device $device, string $title, string $message, int $volume = 100, int $brightness = 100, string $buttonLabel = 'Dismiss'): DeviceCommand
    {
        if ($device->status === DeviceStatus::OFFLINE) {
            throw new DeviceUnavailableException('Device is offline and cannot show an alert.');
        }

        $volume = max(0, min(100, $volume));
        $brightness = max(0, min(100, $brightness));
        $buttonLabel = trim($buttonLabel) !== '' ? $buttonLabel : 'Dismiss';

        // Remember the settings on the device so the dashboard form can
        // pre-fill them next time without the admin re-entering anything.
        $device->forceFill([
            'alert_defaults' => [
                'title' => $title,
                'message' => $message,
                'volume' => $volume,
                'brightness' => $brightness,
                'button_label' => $buttonLabel,
            ],
        ])->save();

        $command = DeviceCommand::query()->create([
            'device_id' => $device->id,
            'recording_id' => null,
            'command_id' => (string) Str::uuid(),
            'command' => CommandType::SHOW_ALERT,
            'payload' => ['title' => $title, 'message' => $message, 'volume' => $volume, 'brightness' => $brightness, 'button_label' => $buttonLabel],
            'status' => CommandStatus::SENT,
            'sent_at' => now(),
        ]);

        $command->load('device');
        AlertCommandRequested::dispatch($command);

        return $command;
    }
}
