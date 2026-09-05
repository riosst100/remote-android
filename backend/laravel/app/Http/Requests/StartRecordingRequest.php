<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartRecordingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'device_id' => ['required', 'integer', 'exists:devices,id'],
            'preset' => ['required', 'string', 'in:LOW,MEDIUM,HIGH,LOSSLESS'],
        ];
    }
}
