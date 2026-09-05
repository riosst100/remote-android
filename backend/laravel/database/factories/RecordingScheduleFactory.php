<?php

namespace Database\Factories;

use App\Enums\RecordingPreset;
use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;

class RecordingScheduleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'day_of_week' => 5, // Friday
            'time_of_day' => '03:30:00',
            'preset' => RecordingPreset::HIGH,
            'duration_minutes' => 30,
            'is_active' => true,
        ];
    }
}
