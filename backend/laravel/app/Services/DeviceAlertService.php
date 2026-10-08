<?php

namespace App\Services;

use App\Enums\CommandStatus;
use App\Enums\CommandType;
use App\Enums\DeviceStatus;
use App\Events\AlertCommandRequested;
use App\Events\DismissAlertCommandRequested;
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
     *
     * $rememberDefaults is false for sensor-rule popups, so an automated
     * alert never overwrites what the admin last typed into the manual form.
     */
    public function sendAlert(Device $device, string $title, string $message, int $volume = 100, int $brightness = 100, string $buttonLabel = 'Dismiss', bool $rememberDefaults = true): DeviceCommand
    {
        if ($device->status === DeviceStatus::OFFLINE) {
            throw new DeviceUnavailableException('Device is offline and cannot show an alert.');
        }

        $volume = max(0, min(100, $volume));
        $brightness = max(0, min(100, $brightness));
        $buttonLabel = trim($buttonLabel) !== '' ? $buttonLabel : 'Dismiss';

        // Remember the settings on the device so the dashboard form can
        // pre-fill them next time without the admin re-entering anything.
        if ($rememberDefaults) {
            $device->forceFill([
                'alert_defaults' => [
                    'title' => $title,
                    'message' => $message,
                    'volume' => $volume,
                    'brightness' => $brightness,
                    'button_label' => $buttonLabel,
                ],
            ])->save();
        }

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

    /**
     * Dispatch a DISMISS_ALERT command so the device closes any
     * currently-showing alert popup. Fire-and-forget, same as flash.
     */
    public function dismissAlert(Device $device): DeviceCommand
    {
        if ($device->status === DeviceStatus::OFFLINE) {
            throw new DeviceUnavailableException('Device is offline and cannot dismiss an alert.');
        }

        $command = DeviceCommand::query()->create([
            'device_id' => $device->id,
            'recording_id' => null,
            'command_id' => (string) Str::uuid(),
            'command' => CommandType::DISMISS_ALERT,
            'payload' => [],
            'status' => CommandStatus::SENT,
            'sent_at' => now(),
        ]);

        $command->load('device');
        DismissAlertCommandRequested::dispatch($command);

        return $command;
    }
}
