<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReportDeviceErrorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'error_code' => ['required', 'string', 'in:MIC_PERMISSION_DENIED,MICROPHONE_UNAVAILABLE,ENCODER_UNAVAILABLE,UNSUPPORTED_CONFIGURATION,STORAGE_ERROR,NETWORK_ERROR,WEBSOCKET_ERROR,UPLOAD_ERROR,FINALIZATION_ERROR,SERVICE_ERROR'],
            'message' => ['nullable', 'string', 'max:1000'],
            'recording_id' => ['nullable', 'uuid', 'exists:recordings,uuid'],
        ];
    }
}
