<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateDeviceRecordingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // LOSSLESS excluded, matches StoreRecordingScheduleRequest.
            'preset' => ['required', 'string', 'in:LOW,MEDIUM,HIGH'],
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
