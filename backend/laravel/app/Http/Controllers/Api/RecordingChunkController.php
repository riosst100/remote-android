<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ChecksumMismatchException;
use App\Http\Controllers\Controller;
use App\Http\Requests\UploadChunkRequest;
use App\Http\Resources\RecordingChunkResource;
use App\Models\Recording;
use App\Services\ChunkUploadService;
use Illuminate\Http\JsonResponse;

class RecordingChunkController extends Controller
{
    public function __construct(private readonly ChunkUploadService $service) {}

    public function store(UploadChunkRequest $request, Recording $recording): JsonResponse
    {
        $device = $request->user();

        if ($recording->device_id !== $device->id) {
            return response()->json(['message' => 'This recording does not belong to your device.'], 403);
        }

        try {
            $chunk = $this->service->store(
                $recording,
                $request->file('file'),
                $request->validated('chunk_number'),
                $request->validated('checksum'),
                $request->validated('duration'),
                $request->validated('mime_type'),
            );
        } catch (ChecksumMismatchException $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'UPLOAD_ERROR'], 422);
        }

        return response()->json(['data' => new RecordingChunkResource($chunk)], 201);
    }
}
