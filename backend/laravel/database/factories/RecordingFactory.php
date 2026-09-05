<?php

namespace Database\Factories;

use App\Enums\RecordingPreset;
use App\Enums\RecordingStatus;
use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class RecordingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'device_id' => Device::factory(),
            'status' => RecordingStatus::PENDING,
            'preset' => RecordingPreset::HIGH,
        ];
    }
}
