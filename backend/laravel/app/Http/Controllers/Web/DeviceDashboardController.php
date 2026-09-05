<?php

namespace App\Http\Controllers\Web;

use App\Enums\RecordingPreset;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\AudioConfigurationResolver;
use Illuminate\View\View;

class DeviceDashboardController extends Controller
{
    public function index(): View
    {
        $devices = Device::query()->with(['recordings' => fn ($q) => $q->latest('id')->limit(1)])->orderBy('name')->get();

        return view('devices.index', compact('devices'));
    }

    public function show(Device $device, AudioConfigurationResolver $resolver): View
    {
        $device->load(['recordings' => fn ($q) => $q->latest('id')->limit(20)]);

        $presets = RecordingPreset::cases();
        $previewConfigs = collect($presets)->mapWithKeys(
            fn (RecordingPreset $preset) => [$preset->value => $resolver->resolve($device, $preset)]
        );

        return view('devices.show', compact('device', 'presets', 'previewConfigs'));
    }
}
