<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AcknowledgeCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event' => ['required', 'string', 'in:command_received,recording_started,recording_stopped,recording_error'],
            'configuration' => ['nullable', 'array'],
            'configuration.encoder' => ['nullable', 'string'],
            'configuration.sample_rate' => ['nullable', 'integer'],
            'configuration.bitrate' => ['nullable', 'integer'],
            'configuration.channels' => ['nullable', 'integer'],
            'error_code' => ['nullable', 'string'],
            'error_message' => ['nullable', 'string'],
        ];
    }
}
