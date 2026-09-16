<?php

namespace App\Http\Requests;

use App\Enums\RecordingPreset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'preset' => ['required', 'string', Rule::enum(RecordingPreset::class)],
        ];
    }
}
