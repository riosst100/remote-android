<?php

namespace App\Http\Controllers\Api;

use App\Enums\RecordingPreset;
use App\Exceptions\DeviceUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StartRecordingRequest;
use App\Http\Resources\RecordingResource;
use App\Models\Device;
use App\Models\Recording;
use App\Services\RecordingLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecordingController extends Controller
{
    public function __construct(private readonly RecordingLifecycleService $lifecycle) {}

    public function start(StartRecordingRequest $request): JsonResponse
    {
        $device = Device::query()->findOrFail($request->validated('device_id'));
        $preset = RecordingPreset::from($request->validated('preset'));

        try {
            $recording = $this->lifecycle->start($device, $preset);
        } catch (DeviceUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['data' => new RecordingResource($recording->fresh())], 201);
    }

    public function stop(Recording $recording): JsonResponse
    {
        $recording = $this->lifecycle->stop($recording);

        return response()->json(['data' => new RecordingResource($recording->fresh())]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Recording::query()->with('device')->withCount('chunks')->latest('id');

        if ($request->filled('device_id')) {
            $query->where('device_id', $request->integer('device_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $recordings = $query->paginate(50);

        return response()->json([
            'data' => RecordingResource::collection($recordings),
            'meta' => ['total' => $recordings->total()],
        ]);
    }

    public function show(Recording $recording): JsonResponse
    {
        $recording->load(['device', 'chunks']);

        return response()->json(['data' => new RecordingResource($recording)]);
    }
}
