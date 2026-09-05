<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            // LOSSLESS excluded — see DeviceDashboardController::selectablePresets.
            'preset' => ['required', 'string', 'in:LOW,MEDIUM,HIGH'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:480'],
        ];
    }
}
