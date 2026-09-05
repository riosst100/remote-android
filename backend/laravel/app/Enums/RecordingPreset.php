<?php

namespace App\Enums;

enum RecordingPreset: string
{
    case LOW = 'LOW';
    case MEDIUM = 'MEDIUM';
    case HIGH = 'HIGH';
    case LOSSLESS = 'LOSSLESS';

    /**
     * Ordered fallback ladder of (sampleRate, bitrate) targets for this preset.
     * The Android agent walks this ladder and picks the first configuration
     * its hardware/encoder actually supports.
     *
     * @return array<int, array{sample_rate:int,bitrate:int,channels:int,encoder:string}>
     */
    public function targetLadder(): array
    {
        return match ($this) {
            self::LOW => [
                ['sample_rate' => 22050, 'bitrate' => 32000, 'channels' => 1, 'encoder' => 'aac'],
                ['sample_rate' => 16000, 'bitrate' => 24000, 'channels' => 1, 'encoder' => 'aac'],
            ],
            self::MEDIUM => [
                ['sample_rate' => 44100, 'bitrate' => 96000, 'channels' => 1, 'encoder' => 'aac'],
                ['sample_rate' => 44100, 'bitrate' => 64000, 'channels' => 1, 'encoder' => 'aac'],
            ],
            self::HIGH => [
                ['sample_rate' => 48000, 'bitrate' => 256000, 'channels' => 1, 'encoder' => 'aac'],
                ['sample_rate' => 44100, 'bitrate' => 192000, 'channels' => 1, 'encoder' => 'aac'],
                ['sample_rate' => 44100, 'bitrate' => 128000, 'channels' => 1, 'encoder' => 'aac'],
            ],
            self::LOSSLESS => [
                ['sample_rate' => 48000, 'bitrate' => 0, 'channels' => 1, 'encoder' => 'flac'],
                ['sample_rate' => 44100, 'bitrate' => 0, 'channels' => 1, 'encoder' => 'flac'],
            ],
        };
    }
}
