<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSensorRulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rules' => ['present', 'array', 'max:20'],

            'rules.*.action' => ['required', Rule::in(['POPUP', 'FLASH_ON', 'FLASH_OFF', 'VIDEO_START', 'VIDEO_STOP'])],

            // Conditions — all provided ones must hold (AND). Any omitted/null
            // condition is ignored by the device.
            'rules.*.when' => ['required', 'array'],
            'rules.*.when.motion' => ['nullable', Rule::in(['STILL', 'PICKED_UP', 'PUT_DOWN', 'MOVING'])],
            'rules.*.when.lux_op' => ['nullable', Rule::in(['lt', 'gt'])],
            'rules.*.when.lux_value' => ['nullable', 'numeric', 'min:0', 'required_with:rules.*.when.lux_op'],
            'rules.*.when.proximity' => ['nullable', Rule::in(['near', 'far'])],

            // Debounce: the conditions must hold continuously for this many
            // seconds before the rule fires, so a momentary blip (a passing
            // shadow dropping lux to 0) doesn't trigger it. 0/absent = instant.
            'rules.*.when.for_seconds' => ['nullable', 'integer', 'between:0,3600'],

            // Optional day/time window — the rule only applies on these
            // weekdays (0 = Sunday) and inside [time_from, time_to).
            'rules.*.when.days' => ['nullable', 'array'],
            'rules.*.when.days.*' => ['integer', 'between:0,6'],
            'rules.*.when.time_from' => ['nullable', 'date_format:H:i', 'required_with:rules.*.when.time_to'],
            'rules.*.when.time_to' => ['nullable', 'date_format:H:i', 'required_with:rules.*.when.time_from'],

            // Only meaningful for VIDEO_START: also turn the flash on once
            // the recording has started.
            'rules.*.with_flash' => ['nullable', 'boolean'],

            // Popup copy — only meaningful (and required) when action is POPUP.
            'rules.*.popup' => ['nullable', 'array'],
            'rules.*.popup.title' => ['nullable', 'string', 'max:120'],
            'rules.*.popup.message' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            foreach ($this->input('rules', []) as $i => $rule) {
                $when = $rule['when'] ?? [];
                $hasCondition = ! empty($when['motion']) || ! empty($when['lux_op']) || ! empty($when['proximity']);
                if (! $hasCondition) {
                    $validator->errors()->add("rules.$i.when", 'Each rule needs at least one condition.');
                }

                if (($rule['action'] ?? null) === 'POPUP') {
                    $title = $rule['popup']['title'] ?? null;
                    $message = $rule['popup']['message'] ?? null;
                    if (blank($title) || blank($message)) {
                        $validator->errors()->add("rules.$i.popup", 'A popup rule needs a title and message.');
                    }
                }
            }
        });
    }
}
