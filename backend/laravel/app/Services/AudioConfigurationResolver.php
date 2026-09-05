<?php

namespace App\Services;

use App\Enums\RecordingPreset;
use App\Models\Device;

class AudioConfigurationResolver
{
    /**
     * Resolve the requested preset's ladder down to the first target the
     * device claims to support. This is only the *proposed* configuration
     * sent with the START_RECORDING command — the Android agent performs
     * its own final capability check and reports back whichever config it
     * actually used, since capability data may be incomplete or stale.
     *
     * @return array{sample_rate:int,bitrate:int,channels:int,encoder:string}
     */
    public function resolve(Device $device, RecordingPreset $preset): array
    {
        $capabilities = $device->capabilities ?? [];
        $supportedEncoders = $capabilities['encoders'] ?? [];
        $supportedRates = $capabilities['sample_rates'] ?? [];

        $ladder = $preset->targetLadder();

        foreach ($ladder as $target) {
            $encoderOk = empty($supportedEncoders) || in_array($target['encoder'], $supportedEncoders, true);
            $rateOk = empty($supportedRates) || in_array($target['sample_rate'], $supportedRates, true);

            if ($encoderOk && $rateOk) {
                return $target;
            }
        }

        // Nothing matched declared capabilities — fall back to the most
        // conservative rung of the ladder and let the device negotiate further.
        return end($ladder);
    }
}
