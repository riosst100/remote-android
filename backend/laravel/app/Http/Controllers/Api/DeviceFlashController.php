<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DeviceUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SetDeviceFlashRequest;
use App\Models\Device;
use App\Services\DeviceFlashService;
use Illuminate\Http\JsonResponse;

class DeviceFlashController extends Controller
{
    public function __construct(private readonly DeviceFlashService $service) {}

    public function store(SetDeviceFlashRequest $request, Device $device): JsonResponse
    {
        try {
            $command = $this->service->setFlash($device, $request->boolean('on'));
        } catch (DeviceUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['data' => [
            'command_id' => $command->command_id,
            'command' => $command->command->value,
        ]], 202);
    }
}
