<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadChunkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'chunk_number' => ['required', 'integer', 'min:1'],
            'checksum' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/'],
            'duration' => ['nullable', 'integer', 'min:0'],
            'file' => ['required', 'file', 'max:20480'],
        ];
    }
}
