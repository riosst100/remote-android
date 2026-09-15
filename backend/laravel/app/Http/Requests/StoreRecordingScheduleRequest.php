<?php

namespace App\Http\Requests;

use App\Enums\RecordingPreset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRecordingScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'day_of_week' => ['required', 'integer', 'between:0,6'],
            'time_of_day' => ['required', 'date_format:H:i'],
            'preset' => ['required', 'string', Rule::enum(RecordingPreset::class)],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:480'],
        ];
    }
}
