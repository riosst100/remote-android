<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\FinalizationException;
use App\Http\Controllers\Controller;
use App\Http\Resources\RecordingResource;
use App\Models\Recording;
use App\Services\RecordingFinalizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecordingCompletionController extends Controller
{
    public function __construct(private readonly RecordingFinalizationService $service) {}

    public function __invoke(Request $request, Recording $recording): JsonResponse
    {
        $device = $request->user();

        if ($recording->device_id !== $device->id) {
            return response()->json(['message' => 'This recording does not belong to your device.'], 403);
        }

        try {
            $recording = $this->service->finalize($recording);
        } catch (FinalizationException $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'FINALIZATION_ERROR'], 422);
        }

        return response()->json(['data' => new RecordingResource($recording)]);
    }
}
