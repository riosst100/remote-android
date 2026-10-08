<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveSensorRulesRequest;
use App\Models\Device;
use Illuminate\Http\JsonResponse;

/**
 * Saves the per-device sensor-automation rules. They are evaluated server-side
 * against each sensor report the device sends (see SensorRuleEngine).
 */
class DeviceSensorRulesController extends Controller
{
    public function store(SaveSensorRulesRequest $request, Device $device): JsonResponse
    {
        $rules = collect($request->validated('rules'))
            ->map(function (array $rule) {
                $when = $rule['when'] ?? [];

                $normalized = [
                    'action' => $rule['action'],
                    'when' => [
                        'motion' => $when['motion'] ?? null,
                        'lux_op' => $when['lux_op'] ?? null,
                        'lux_value' => isset($when['lux_value']) ? (float) $when['lux_value'] : null,
                        'proximity' => $when['proximity'] ?? null,
                        'days' => ! empty($when['days'])
                            ? array_values(array_unique(array_map('intval', $when['days'])))
                            : null,
                        'time_from' => $when['time_from'] ?? null,
                        'time_to' => $when['time_to'] ?? null,
                    ],
                ];

                if ($rule['action'] === 'POPUP') {
                    $normalized['popup'] = [
                        'title' => $rule['popup']['title'],
                        'message' => $rule['popup']['message'],
                    ];
                }

                return $normalized;
            })
            ->values()
            ->all();

        $device->forceFill(['sensor_rules' => $rules])->save();

        return response()->json(['data' => $rules]);
    }
}
