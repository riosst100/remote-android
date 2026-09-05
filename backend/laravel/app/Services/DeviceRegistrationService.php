<?php

namespace App\Services;

use App\Enums\DeviceStatus;
use App\Models\Device;
use Illuminate\Support\Facades\DB;

class DeviceRegistrationService
{
    /**
     * Register a device (or re-register an existing one, e.g. after
     * reinstall/app-update) and issue it a fresh device-scoped API token.
     *
     * @return array{device: Device, token: string}
     */
    public function register(array $attributes): array
    {
        return DB::transaction(function () use ($attributes) {
            /** @var Device $device */
            $device = Device::query()->updateOrCreate(
                ['device_uuid' => $attributes['device_uuid']],
                [
                    'name' => $attributes['device_name'] ?? null,
                    'manufacturer' => $attributes['manufacturer'] ?? null,
                    'model' => $attributes['model'] ?? null,
                    'android_version' => $attributes['android_version'] ?? null,
                    'app_version' => $attributes['app_version'] ?? null,
                    'capabilities' => $attributes['audio_capabilities'] ?? [],
                    'status' => DeviceStatus::ONLINE,
                    'last_seen_at' => now(),
                ]
            );

            // Rotating the token on every (re-)registration means a lost/wiped
            // device can't keep using a leaked credential once it re-registers.
            $device->tokens()->delete();
            $token = $device->createToken('device:'.$device->device_uuid)->plainTextToken;

            return ['device' => $device, 'token' => $token];
        });
    }
}
