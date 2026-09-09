<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateDeviceRecordingRequest;
use App\Http\Resources\RecordingResource;
use App\Services\DeviceRecordingService;
use Illuminate\Http\JsonResponse;

class DeviceRecordingController extends Controller
{
    public function __construct(private readonly DeviceRecordingService $service) {}

    public function store(CreateDeviceRecordingRequest $request): JsonResponse
    {
        $device = $request->user();

        $recording = $this->service->create($device, $request->validated());

        return response()->json(['data' => new RecordingResource($recording)], 201);
    }
}
