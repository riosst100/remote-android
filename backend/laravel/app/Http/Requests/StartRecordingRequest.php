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
            // LOSSLESS is intentionally excluded here: the Android agent's
            // recorder only implements the AAC path today, so accepting it
            // would silently fall back to AAC rather than actually
            // recording lossless.
            'preset' => ['required', 'string', 'in:LOW,MEDIUM,HIGH'],
        ];
    }
}
