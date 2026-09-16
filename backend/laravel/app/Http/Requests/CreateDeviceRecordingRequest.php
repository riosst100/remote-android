<?php

namespace App\Http\Requests;

use App\Enums\RecordingPreset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateDeviceRecordingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preset' => ['required', 'string', Rule::enum(RecordingPreset::class)],
            'client_recording_id' => ['required', 'uuid'],
            'encoder' => ['nullable', 'string'],
            'sample_rate' => ['nullable', 'integer'],
            'bitrate' => ['nullable', 'integer'],
            'channels' => ['nullable', 'integer'],
            'started_at' => ['required', 'date'],
            'schedule_id' => ['nullable', 'integer', 'exists:recording_schedules,id'],
        ];
    }
}
