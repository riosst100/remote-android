<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\HeartbeatRequest;
use App\Http\Requests\RegisterDeviceRequest;
use App\Http\Resources\DeviceResource;
use App\Models\Device;
use App\Services\DeviceHeartbeatService;
use App\Services\DeviceRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    public function register(RegisterDeviceRequest $request, DeviceRegistrationService $service): JsonResponse
    {
        $result = $service->register($request->validated());

        return response()->json([
            'device' => new DeviceResource($result['device']),
            'token' => $result['token'],
        ], 201);
    }

    public function heartbeat(HeartbeatRequest $request, DeviceHeartbeatService $service): JsonResponse
    {
        $device = $request->user();

        $device = $service->record($device, $request->validated('status'));

        return response()->json(['device' => new DeviceResource($device)]);
    }

    public function index(): JsonResponse
    {
        $devices = Device::query()->with('recordings')->latest('last_seen_at')->paginate(50);

        return response()->json([
            'data' => DeviceResource::collection($devices),
            'meta' => ['total' => $devices->total()],
        ]);
    }

    public function show(Device $device): JsonResponse
    {
        $device->load('recordings');

        return response()->json(['data' => new DeviceResource($device)]);
    }
}
