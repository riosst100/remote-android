<?php

namespace App\Services;

use App\Exceptions\DeviceUnavailableException;
use App\Models\Device;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Evaluates a device's saved sensor-automation rules against each sensor
 * report and fires the matching action through the same services the
 * dashboard's manual buttons use.
 *
 * Rules are edge-triggered: an action fires only when a rule's conditions go
 * from "not matching" to "matching", never again while they keep matching.
 * That is what keeps manual control usable alongside automation — if a rule
 * turned the flash on and the admin turns it off by hand, the rule does not
 * fight back until its condition clears and then holds again.
 */
class SensorRuleEngine
{
    public function __construct(
        private readonly DeviceFlashService $flash,
        private readonly DeviceAlertService $alerts,
    ) {}

    /**
     * @param  array<string, mixed>  $sensors  The reading as reported by the device.
     * @return list<string> The actions fired, for logging/tests.
     */
    public function evaluate(Device $device, array $sensors): array
    {
        $rules = $device->sensor_rules ?? [];
        $cacheKey = "sensor-rules:{$device->id}:matched";

        if ($rules === []) {
            Cache::forget($cacheKey);

            return [];
        }

        // Keyed by a fingerprint of each rule, so editing a rule resets only
        // that rule's edge state and reordering rules changes nothing.
        $previous = Cache::get($cacheKey, []);
        $current = [];
        $fired = [];

        foreach ($rules as $rule) {
            $key = md5(json_encode($rule));
            $matches = $this->matches($rule['when'] ?? [], $sensors);
            $current[$key] = $matches;

            if ($matches && ! ($previous[$key] ?? false) && $this->fire($device, $rule, $sensors)) {
                $fired[] = $rule['action'];
            }
        }

        Cache::put($cacheKey, $current, now()->addDay());

        return $fired;
    }

    /**
     * All provided conditions must hold (AND); omitted ones are ignored. A
     * condition on a sensor the reading doesn't include never holds.
     *
     * @param  array<string, mixed>  $when
     * @param  array<string, mixed>  $sensors
     */
    private function matches(array $when, array $sensors): bool
    {
        if (! empty($when['motion']) && ($sensors['motion'] ?? null) !== $when['motion']) {
            return false;
        }

        if (! empty($when['lux_op'])) {
            if (! isset($sensors['lux'], $when['lux_value'])) {
                return false;
            }
            $lux = (float) $sensors['lux'];
            $threshold = (float) $when['lux_value'];
            if ($when['lux_op'] === 'lt' ? $lux >= $threshold : $lux <= $threshold) {
                return false;
            }
        }

        if (! empty($when['proximity'])) {
            if (! isset($sensors['proximity_near'])) {
                return false;
            }
            if ((bool) $sensors['proximity_near'] !== ($when['proximity'] === 'near')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Sends the rule's command, skipping it when the device already reports
     * the target state (flash already on/off, popup already showing).
     *
     * @param  array<string, mixed>  $rule
     * @param  array<string, mixed>  $sensors
     */
    private function fire(Device $device, array $rule, array $sensors): bool
    {
        try {
            switch ($rule['action']) {
                case 'FLASH_ON':
                case 'FLASH_OFF':
                    $on = $rule['action'] === 'FLASH_ON';
                    if (isset($sensors['flash_on']) && (bool) $sensors['flash_on'] === $on) {
                        return false;
                    }
                    $this->flash->setFlash($device, $on);

                    return true;

                case 'POPUP':
                    if (! empty($sensors['popup_shown'])) {
                        return false;
                    }
                    $popup = $rule['popup'] ?? [];
                    $this->alerts->sendAlert(
                        $device,
                        (string) ($popup['title'] ?? 'Attention'),
                        (string) ($popup['message'] ?? ''),
                        (int) ($popup['volume'] ?? 100),
                        (int) ($popup['brightness'] ?? 100),
                        rememberDefaults: false,
                    );

                    return true;
            }
        } catch (DeviceUnavailableException $e) {
            Log::info('Sensor rule skipped: device unavailable', ['device_id' => $device->id, 'action' => $rule['action']]);
        }

        return false;
    }
}
