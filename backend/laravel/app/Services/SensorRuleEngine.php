<?php

namespace App\Services;

use App\Enums\MediaKind;
use App\Enums\RecordingStatus;
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
        private readonly RecordingLifecycleService $recordings,
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
        // Day-of-week / time-of-day gate: when set, the rule only applies on
        // the listed weekdays and inside the [from, to) window. Evaluated
        // first so an out-of-schedule rule costs nothing else.
        if (! $this->withinSchedule($when)) {
            return false;
        }

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
     * Checks the current time against a rule's optional day/time window.
     * No days and no time range means "always". Days are Carbon weekday
     * numbers (0 = Sunday). A from <= to window is same-day and end-exclusive
     * (04:00–05:00); from > to wraps past midnight (22:00–05:00). Evaluated
     * in the configured rule timezone so the hours mean local wall-clock time.
     *
     * @param  array<string, mixed>  $when
     */
    private function withinSchedule(array $when): bool
    {
        $days = $when['days'] ?? null;
        $from = $when['time_from'] ?? null;
        $to = $when['time_to'] ?? null;

        if (empty($days) && blank($from) && blank($to)) {
            return true;
        }

        $now = now(config('recorder.rule_timezone', config('app.timezone')));

        if (! empty($days) && ! in_array((int) $now->dayOfWeek, array_map('intval', $days), true)) {
            return false;
        }

        if (! blank($from) && ! blank($to)) {
            $current = $now->format('H:i');
            if ($from <= $to) {
                if ($current < $from || $current >= $to) {
                    return false;
                }
            } elseif ($current < $from && $current >= $to) {
                // Overnight window: outside only when before `from` AND at/after `to`.
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
                    // Volume and brightness follow the dashboard's Alert
                    // Popup settings, so one slider controls every popup.
                    $defaults = $device->alert_defaults ?? [];
                    $this->alerts->sendAlert(
                        $device,
                        (string) ($popup['title'] ?? 'Attention'),
                        (string) ($popup['message'] ?? ''),
                        (int) ($defaults['volume'] ?? 100),
                        (int) ($defaults['brightness'] ?? 100),
                        rememberDefaults: false,
                    );

                    return true;

                case 'VIDEO_START':
                    // Skip if a video is already capturing (manual or a prior
                    // rule); startVideo is idempotent per device either way.
                    if (! empty($sensors['video_recording'])) {
                        return false;
                    }
                    $this->recordings->startVideo($device);
                    if (! empty($rule['with_flash'])) {
                        // Light the scene once capture has begun. The torch
                        // rides the same camera session and is released with
                        // it when the recording stops, so no FLASH_OFF is
                        // needed. A failed flash must not undo the recording.
                        try {
                            $this->flash->setFlash($device, true);
                        } catch (DeviceUnavailableException $e) {
                            Log::info('Sensor rule: flash-with-video skipped', ['device_id' => $device->id]);
                        }
                    }

                    return true;

                case 'VIDEO_STOP':
                    if (empty($sensors['video_recording'])) {
                        return false;
                    }
                    $active = $device->recordings()
                        ->where('media_kind', MediaKind::VIDEO)
                        ->whereNotIn('status', [RecordingStatus::COMPLETED, RecordingStatus::FAILED])
                        ->latest('id')
                        ->first();
                    if (! $active) {
                        return false;
                    }
                    $this->recordings->stopVideo($active);

                    return true;
            }
        } catch (DeviceUnavailableException $e) {
            Log::info('Sensor rule skipped: device unavailable', ['device_id' => $device->id, 'action' => $rule['action']]);
        }

        return false;
    }
}
