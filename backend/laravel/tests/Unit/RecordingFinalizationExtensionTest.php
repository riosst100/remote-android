<?php

namespace Tests\Unit;

use App\Services\ChunkStorageService;
use App\Services\RecordingFinalizationService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class RecordingFinalizationExtensionTest extends TestCase
{
    private function extensionFor(string $mimeType): string
    {
        $service = new RecordingFinalizationService(new ChunkStorageService);
        $method = new ReflectionMethod($service, 'extensionFor');
        $method->setAccessible(true);

        return $method->invoke($service, $mimeType);
    }

    public function test_standard_aac_mime_types_map_to_aac(): void
    {
        $this->assertSame('aac', $this->extensionFor('audio/aac'));
        $this->assertSame('aac', $this->extensionFor('audio/aac-adts'));
    }

    public function test_vendor_specific_aac_adts_mime_types_still_map_to_aac(): void
    {
        // Samsung devices' fileinfo/magic-byte detector reports AAC-ADTS
        // audio under this vendor-specific string rather than "audio/aac".
        $this->assertSame('aac', $this->extensionFor('audio/x-hx-aac-adts'));
    }

    public function test_opus_and_ogg_mime_types_map_to_ogg(): void
    {
        $this->assertSame('ogg', $this->extensionFor('audio/opus'));
        $this->assertSame('ogg', $this->extensionFor('audio/ogg'));
    }

    public function test_flac_mime_type_maps_to_flac(): void
    {
        $this->assertSame('flac', $this->extensionFor('audio/flac'));
    }

    public function test_unrecognized_mime_type_falls_back_to_audio(): void
    {
        $this->assertSame('audio', $this->extensionFor('application/octet-stream'));
    }
}
