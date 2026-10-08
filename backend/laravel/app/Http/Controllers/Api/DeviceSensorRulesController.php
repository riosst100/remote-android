<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveSensorRulesRequest;
use App\Models\Device;
use Illuminate\Http\JsonResponse;

/**
 * Saves the per-device sensor-automation rules. The device picks them up on
 * its next heartbeat and evaluates them locally against live sensor data.
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
                    ],
                ];

                if ($rule['action'] === 'POPUP') {
                    $normalized['popup'] = [
                        'title' => $rule['popup']['title'],
                        'message' => $rule['popup']['message'],
                        'volume' => (int) ($rule['popup']['volume'] ?? 100),
                        'brightness' => (int) ($rule['popup']['brightness'] ?? 100),
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
