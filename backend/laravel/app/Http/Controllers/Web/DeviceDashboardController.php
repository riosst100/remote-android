<?php

namespace App\Http\Controllers\Web;

use App\Enums\RecordingPreset;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\AudioConfigurationResolver;
use Illuminate\View\View;

class DeviceDashboardController extends Controller
{
    public function index(AudioConfigurationResolver $resolver): View
    {
        $devices = Device::query()->with(['recordings' => fn ($q) => $q->latest('id')->limit(1)])->orderBy('name')->get();
        $presets = $this->selectablePresets();

        $presetConfigsByDevice = $devices->mapWithKeys(
            fn (Device $device) => [$device->id => $this->resolveConfigsFor($device, $presets, $resolver)]
        );

        return view('devices.index', compact('devices', 'presets', 'presetConfigsByDevice'));
    }

    public function show(Device $device, AudioConfigurationResolver $resolver): View
    {
        $device->load(['recordings' => fn ($q) => $q->latest('id')->limit(20)]);

        $presets = $this->selectablePresets();
        $previewConfigs = $this->resolveConfigsFor($device, $presets, $resolver);

        return view('devices.show', compact('device', 'presets', 'previewConfigs'));
    }

    /**
     * @return array<RecordingPreset>
     */
    private function selectablePresets(): array
    {
        // LOSSLESS is excluded: the Android agent's recorder only
        // implements the AAC path today, so offering it would silently
        // fall back to AAC rather than actually recording lossless.
        return array_filter(RecordingPreset::cases(), fn (RecordingPreset $preset) => $preset !== RecordingPreset::LOSSLESS);
    }

    /**
     * @param  array<RecordingPreset>  $presets
     */
    private function resolveConfigsFor(Device $device, array $presets, AudioConfigurationResolver $resolver)
    {
        return collect($presets)->mapWithKeys(
            fn (RecordingPreset $preset) => [$preset->value => $resolver->resolve($device, $preset)]
        );
    }
}
