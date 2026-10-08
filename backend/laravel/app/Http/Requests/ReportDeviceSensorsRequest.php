<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReportDeviceSensorsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Ambient light, in lux. Null when the device has no light sensor.
            'lux' => ['nullable', 'numeric', 'min:0'],

            // Coarse motion state derived on-device from the accelerometer.
            'motion' => ['nullable', 'string', 'in:STILL,PICKED_UP,PUT_DOWN,MOVING'],
            // Raw accelerometer magnitude (m/s^2), for display alongside motion.
            'accel_magnitude' => ['nullable', 'numeric', 'min:0'],

            // Proximity: true = something is close/covering the sensor.
            'proximity_near' => ['nullable', 'boolean'],

            // Live actuator state, so the dashboard can show whether a popup
            // is currently showing and whether the flashlight is on.
            'popup_shown' => ['nullable', 'boolean'],
            'flash_on' => ['nullable', 'boolean'],
            'video_recording' => ['nullable', 'boolean'],
        ];
    }
}
