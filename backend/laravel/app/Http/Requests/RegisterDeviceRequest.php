<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'device_uuid' => ['required', 'uuid'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'android_version' => ['nullable', 'string', 'max:50'],
            'app_version' => ['nullable', 'string', 'max:50'],
            'audio_capabilities' => ['nullable', 'array'],
            'audio_capabilities.encoders' => ['nullable', 'array'],
            'audio_capabilities.sample_rates' => ['nullable', 'array'],
            'audio_capabilities.channels' => ['nullable', 'array'],
            'audio_capabilities.bitrates' => ['nullable', 'array'],
        ];
    }
}
