<?php

namespace Database\Factories;

use App\Enums\DeviceStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class DeviceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'device_uuid' => (string) Str::uuid(),
            'name' => $this->faker->words(2, true),
            'manufacturer' => $this->faker->randomElement(['Samsung', 'Google', 'Xiaomi', 'OnePlus']),
            'model' => $this->faker->bothify('Model-####'),
            'android_version' => $this->faker->randomElement(['11', '12', '13', '14', '15']),
            'app_version' => '1.0.0',
            'capabilities' => [
                'encoders' => ['aac', 'opus'],
                'sample_rates' => [44100, 48000],
                'channels' => [1, 2],
                'bitrates' => [64000, 128000, 256000],
            ],
            'status' => DeviceStatus::OFFLINE,
            'last_seen_at' => now(),
        ];
    }
}
