<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceScheduleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceScheduleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $device = $request->user(); // Device, via 'device' guard

        $schedules = $device->schedules()
            ->where('is_active', true)
            ->orderBy('day_of_week')->orderBy('time_of_day')
            ->get();

        return response()->json([
            'data' => DeviceScheduleResource::collection($schedules),
            'meta' => ['synced_at' => now()->toIso8601String()],
        ]);
    }
}
