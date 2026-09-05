<?php

namespace App\Http\Controllers\Web;

use App\Enums\DeviceStatus;
use App\Enums\RecordingStatus;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Recording;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $devices = Device::query()->get();

        $stats = [
            'total_devices' => $devices->count(),
            'online_devices' => $devices->where('status', DeviceStatus::ONLINE)->count(),
            'recording_devices' => $devices->where('status', DeviceStatus::RECORDING)->count(),
            'offline_devices' => $devices->where('status', DeviceStatus::OFFLINE)->count(),
            'active_recordings' => Recording::query()->whereNotIn('status', [RecordingStatus::COMPLETED, RecordingStatus::FAILED])->count(),
            'completed_recordings' => Recording::query()->where('status', RecordingStatus::COMPLETED)->count(),
        ];

        $recentRecordings = Recording::query()->with('device')->latest('id')->limit(10)->get();

        return view('dashboard', compact('stats', 'recentRecordings'));
    }
}
