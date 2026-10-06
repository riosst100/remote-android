<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendDeviceAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'max:500'],
            // Label for the dismiss button shown on the device. Optional —
            // defaults to "Dismiss" when omitted.
            'button_label' => ['sometimes', 'string', 'max:40'],
            // Alarm volume the device should use while the alert shows, as a
            // percentage. Optional — defaults to 100 (max) when omitted.
            'volume' => ['sometimes', 'integer', 'between:0,100'],
            // Screen brightness the device should use while the alert shows,
            // as a percentage. Optional — defaults to 100 (max) when omitted.
            'brightness' => ['sometimes', 'integer', 'between:0,100'],
        ];
    }
}
