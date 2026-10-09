<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceVideoSettingsController extends Controller
{
    /**
     * Saves the per-device maximum video-recording duration. 0 (or blank)
     * clears the limit; otherwise a started video auto-stops after N minutes.
     */
    public function update(Request $request, Device $device): JsonResponse
    {
        $validated = $request->validate([
            'max_video_minutes' => ['nullable', 'integer', 'between:0,1440'],
        ]);

        $minutes = (int) ($validated['max_video_minutes'] ?? 0);
        $device->forceFill(['max_video_minutes' => $minutes > 0 ? $minutes : null])->save();

        return response()->json(['data' => ['max_video_minutes' => $device->max_video_minutes]]);
    }
}
