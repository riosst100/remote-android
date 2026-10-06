<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetDeviceFlashRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'on' => ['required', 'boolean'],
        ];
    }
}
