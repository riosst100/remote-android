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

        // Marks this fetch as the device's authoritative "last pulled
        // schedules at" moment, so the admin dashboard can tell whether a
        // given schedule has actually reached the device yet (see
        // RecordingSchedule::isSyncedToDevice()).
        $device->forceFill(['schedules_synced_at' => now()])->save();

        return response()->json([
            'data' => DeviceScheduleResource::collection($schedules),
            'meta' => ['synced_at' => now()->toIso8601String()],
        ]);
    }
}
