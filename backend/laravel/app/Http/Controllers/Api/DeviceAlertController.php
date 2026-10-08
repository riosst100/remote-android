<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DeviceUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendDeviceAlertRequest;
use App\Models\Device;
use App\Services\DeviceAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceAlertController extends Controller
{
    public function __construct(private readonly DeviceAlertService $service) {}

    public function store(SendDeviceAlertRequest $request, Device $device): JsonResponse
    {
        try {
            $command = $this->service->sendAlert(
                $device,
                $request->string('title'),
                $request->string('message'),
                $request->integer('volume', 100),
                $request->integer('brightness', 100),
                $request->string('button_label', 'Dismiss'),
            );
        } catch (DeviceUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['data' => [
            'command_id' => $command->command_id,
            'command' => $command->command->value,
        ]], 202);
    }

    /**
     * Saves the Alert Popup volume/brightness without sending an alert, so
     * sensor-rule popups follow the dashboard sliders immediately.
     */
    public function saveDefaults(Request $request, Device $device): JsonResponse
    {
        $validated = $request->validate([
            'volume' => ['required', 'integer', 'between:0,100'],
            'brightness' => ['required', 'integer', 'between:0,100'],
        ]);

        $device->forceFill([
            'alert_defaults' => array_merge($device->alert_defaults ?? [], $validated),
        ])->save();

        return response()->json(['data' => $device->alert_defaults]);
    }

    public function dismiss(Device $device): JsonResponse
    {
        try {
            $command = $this->service->dismissAlert($device);
        } catch (DeviceUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['data' => [
            'command_id' => $command->command_id,
            'command' => $command->command->value,
        ]], 202);
    }
}
