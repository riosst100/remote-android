<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRecordingScheduleRequest;
use App\Models\Device;
use App\Models\RecordingSchedule;
use Illuminate\Http\RedirectResponse;

class RecordingScheduleController extends Controller
{
    public function store(StoreRecordingScheduleRequest $request, Device $device): RedirectResponse
    {
        $device->schedules()->create($request->validated());

        return redirect()->route('devices.show', $device)->with('status', 'Schedule added.');
    }

    public function toggle(Device $device, RecordingSchedule $schedule): RedirectResponse
    {
        abort_unless($schedule->device_id === $device->id, 404);

        $schedule->update(['is_active' => ! $schedule->is_active]);

        return redirect()->route('devices.show', $device)->with('status', $schedule->is_active ? 'Schedule enabled.' : 'Schedule disabled.');
    }

    public function destroy(Device $device, RecordingSchedule $schedule): RedirectResponse
    {
        abort_unless($schedule->device_id === $device->id, 404);

        $schedule->delete();

        return redirect()->route('devices.show', $device)->with('status', 'Schedule removed.');
    }
}
