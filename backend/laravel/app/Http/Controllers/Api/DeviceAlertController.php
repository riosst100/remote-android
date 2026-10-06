<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DeviceUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendDeviceAlertRequest;
use App\Models\Device;
use App\Services\DeviceAlertService;
use Illuminate\Http\JsonResponse;

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
}
