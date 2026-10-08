<?php

namespace App\Http\Controllers\Api;

use App\Events\DeviceSensorsUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReportDeviceSensorsRequest;
use Illuminate\Http\JsonResponse;

/**
 * Receives live sensor telemetry from a device and broadcasts it straight
 * to the dashboard. Nothing is persisted — the reading is only meaningful
 * while it is fresh, so it lives entirely in the broadcast payload.
 */
class DeviceSensorsController extends Controller
{
    public function __invoke(ReportDeviceSensorsRequest $request): JsonResponse
    {
        $device = $request->user();

        $sensors = array_filter(
            $request->safe()->only(['lux', 'motion', 'accel_magnitude', 'proximity_near', 'popup_shown', 'flash_on', 'video_recording']),
            static fn ($value) => $value !== null,
        );

        DeviceSensorsUpdated::dispatch($device, $sensors);

        return response()->json(['message' => 'Sensors received.']);
    }
}
