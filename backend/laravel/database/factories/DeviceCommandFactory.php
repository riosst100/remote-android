<?php

namespace Database\Factories;

use App\Enums\CommandStatus;
use App\Enums\CommandType;
use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class DeviceCommandFactory extends Factory
{
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'command_id' => (string) Str::uuid(),
            'command' => CommandType::START_RECORDING,
            'payload' => [],
            'status' => CommandStatus::PENDING,
        ];
    }
}
