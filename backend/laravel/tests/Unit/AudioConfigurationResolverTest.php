<?php

namespace Tests\Unit;

use App\Enums\RecordingPreset;
use App\Models\Device;
use App\Services\AudioConfigurationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AudioConfigurationResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_the_top_of_the_ladder_when_fully_supported(): void
    {
        $device = Device::factory()->create([
            'capabilities' => [
                'encoders' => ['aac'],
                'sample_rates' => [44100, 48000],
            ],
        ]);

        $resolver = app(AudioConfigurationResolver::class);
        $config = $resolver->resolve($device, RecordingPreset::HIGH);

        $this->assertSame(48000, $config['sample_rate']);
        $this->assertSame(256000, $config['bitrate']);
    }

    public function test_falls_back_when_the_top_rung_sample_rate_is_unsupported(): void
    {
        $device = Device::factory()->create([
            'capabilities' => [
                'encoders' => ['aac'],
                'sample_rates' => [44100],
            ],
        ]);

        $resolver = app(AudioConfigurationResolver::class);
        $config = $resolver->resolve($device, RecordingPreset::HIGH);

        $this->assertSame(44100, $config['sample_rate']);
        $this->assertSame(192000, $config['bitrate']);
    }

    public function test_falls_back_to_the_most_conservative_rung_when_nothing_matches(): void
    {
        $device = Device::factory()->create([
            'capabilities' => [
                'encoders' => ['opus'],
                'sample_rates' => [8000],
            ],
        ]);

        $resolver = app(AudioConfigurationResolver::class);
        $config = $resolver->resolve($device, RecordingPreset::HIGH);

        $this->assertSame(44100, $config['sample_rate']);
        $this->assertSame(128000, $config['bitrate']);
    }

    public function test_missing_capability_data_does_not_block_resolution(): void
    {
        $device = Device::factory()->create(['capabilities' => null]);

        $resolver = app(AudioConfigurationResolver::class);
        $config = $resolver->resolve($device, RecordingPreset::MEDIUM);

        $this->assertSame(44100, $config['sample_rate']);
        $this->assertSame(96000, $config['bitrate']);
    }
}
