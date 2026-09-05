<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AcknowledgeCommandRequest;
use App\Models\DeviceCommand;
use App\Services\CommandAcknowledgementService;
use Illuminate\Http\JsonResponse;

class DeviceCommandController extends Controller
{
    public function __construct(private readonly CommandAcknowledgementService $service) {}

    public function acknowledge(AcknowledgeCommandRequest $request, DeviceCommand $command): JsonResponse
    {
        $device = $request->user();

        if ($command->device_id !== $device->id) {
            return response()->json(['message' => 'This command does not belong to your device.'], 403);
        }

        $this->service->acknowledge($command, $request->validated('event'), $request->validated());

        return response()->json(['data' => ['command_id' => $command->command_id, 'status' => $command->fresh()->status->value]]);
    }
}
