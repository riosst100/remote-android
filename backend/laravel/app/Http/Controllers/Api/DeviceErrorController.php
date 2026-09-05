<?php

namespace App\Http\Controllers\Api;

use App\Enums\DeviceStatus;
use App\Enums\RecordingStatus;
use App\Events\DeviceStatusChanged;
use App\Events\RecordingFailed;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReportDeviceErrorRequest;
use App\Models\Recording;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class DeviceErrorController extends Controller
{
    public function __invoke(ReportDeviceErrorRequest $request): JsonResponse
    {
        $device = $request->user();

        Log::warning('Device reported error', [
            'device_id' => $device->id,
            'device_uuid' => $device->device_uuid,
            'error_code' => $request->validated('error_code'),
            'message' => $request->validated('message'),
        ]);

        $device->forceFill(['status' => DeviceStatus::ERROR])->save();
        DeviceStatusChanged::dispatch($device);

        if ($recordingUuid = $request->validated('recording_id')) {
            $recording = Recording::query()->where('uuid', $recordingUuid)->first();

            if ($recording && ! $recording->status->isTerminal()) {
                $recording->forceFill([
                    'status' => RecordingStatus::FAILED,
                    'error_message' => $request->validated('error_code').': '.$request->validated('message'),
                ])->save();

                RecordingFailed::dispatch($recording->fresh());
            }
        }

        return response()->json(['message' => 'Error reported.']);
    }
}
