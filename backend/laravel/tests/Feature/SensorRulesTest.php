<?php

namespace Tests\Feature;

use App\Enums\DeviceStatus;
use App\Events\AlertCommandRequested;
use App\Events\DeviceSensorsUpdated;
use App\Events\FlashCommandRequested;
use App\Models\Device;
use App\Models\User;
use App\Jobs\EvaluateDeviceSensorRules;
use App\Services\RecordingLifecycleService;
use App\Services\SensorRuleEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SensorRulesTest extends TestCase
{
    use RefreshDatabase;

    private Device $device;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([FlashCommandRequested::class, AlertCommandRequested::class, DeviceSensorsUpdated::class]);

        $this->device = Device::factory()->create(['status' => DeviceStatus::ONLINE]);
        $this->token = $this->device->createToken('test')->plainTextToken;
    }

    private function rules(array $rules): void
    {
        $this->device->forceFill(['sensor_rules' => $rules])->save();
    }

    private function report(array $sensors): void
    {
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/devices/sensors', $sensors)
            ->assertOk();
    }

    private function flashRule(string $action, array $when): array
    {
        return ['action' => $action, 'when' => array_merge(['motion' => null, 'lux_op' => null, 'lux_value' => null, 'proximity' => null], $when)];
    }

    public function test_rule_fires_when_its_conditions_become_true(): void
    {
        $this->rules([$this->flashRule('FLASH_ON', ['lux_op' => 'lt', 'lux_value' => 10])]);

        $this->report(['lux' => 200, 'flash_on' => false]);
        $this->assertDatabaseMissing('device_commands', ['device_id' => $this->device->id]);

        $this->report(['lux' => 3, 'flash_on' => false]);
        $this->assertDatabaseHas('device_commands', ['device_id' => $this->device->id, 'command' => 'FLASH_ON']);
        Event::assertDispatchedTimes(FlashCommandRequested::class, 1);
    }

    public function test_rule_does_not_refire_while_conditions_keep_holding_so_manual_control_wins(): void
    {
        $this->rules([$this->flashRule('FLASH_ON', ['lux_op' => 'lt', 'lux_value' => 10])]);

        $this->report(['lux' => 3, 'flash_on' => false]);
        // Admin turned the flash off by hand; it's still dark.
        $this->report(['lux' => 2, 'flash_on' => false]);
        $this->report(['lux' => 4, 'motion' => 'MOVING', 'flash_on' => false]);
        Event::assertDispatchedTimes(FlashCommandRequested::class, 1);

        // Room gets bright, then dark again: the rule re-arms and fires.
        $this->report(['lux' => 300, 'flash_on' => false]);
        $this->report(['lux' => 5, 'flash_on' => false]);
        Event::assertDispatchedTimes(FlashCommandRequested::class, 2);
    }

    public function test_all_conditions_must_hold(): void
    {
        $this->rules([$this->flashRule('FLASH_ON', ['motion' => 'PICKED_UP', 'proximity' => 'far'])]);

        $this->report(['motion' => 'PICKED_UP', 'proximity_near' => true]);
        $this->report(['motion' => 'STILL', 'proximity_near' => false]);
        Event::assertNotDispatched(FlashCommandRequested::class);

        $this->report(['motion' => 'PICKED_UP', 'proximity_near' => false]);
        Event::assertDispatchedTimes(FlashCommandRequested::class, 1);
    }

    public function test_action_is_skipped_when_the_device_is_already_in_that_state(): void
    {
        $this->rules([$this->flashRule('FLASH_OFF', ['lux_op' => 'gt', 'lux_value' => 100])]);

        $this->report(['lux' => 500, 'flash_on' => false]);

        Event::assertNotDispatched(FlashCommandRequested::class);
    }

    public function test_popup_rule_sends_an_alert_without_overwriting_the_manual_alert_defaults(): void
    {
        $this->device->forceFill(['alert_defaults' => ['title' => 'Manual', 'message' => 'typed by admin']])->save();
        $this->rules([[
            'action' => 'POPUP',
            'when' => ['motion' => 'PICKED_UP', 'lux_op' => null, 'lux_value' => null, 'proximity' => null],
            'popup' => ['title' => 'Hey', 'message' => 'Put it back', 'volume' => 80, 'brightness' => 90],
        ]]);

        $this->report(['motion' => 'PICKED_UP', 'popup_shown' => false]);

        $this->assertDatabaseHas('device_commands', ['device_id' => $this->device->id, 'command' => 'SHOW_ALERT']);
        Event::assertDispatched(AlertCommandRequested::class, fn ($e) => $e->command->payload['title'] === 'Hey');
        $this->assertSame('Manual', $this->device->fresh()->alert_defaults['title']);
    }

    public function test_popup_rule_uses_the_dashboard_alert_volume_and_brightness(): void
    {
        $this->device->forceFill(['alert_defaults' => ['volume' => 35, 'brightness' => 60]])->save();
        $this->rules([[
            'action' => 'POPUP',
            'when' => ['motion' => 'PICKED_UP', 'lux_op' => null, 'lux_value' => null, 'proximity' => null],
            'popup' => ['title' => 'Hey', 'message' => 'Put it back'],
        ]]);

        $this->report(['motion' => 'PICKED_UP', 'popup_shown' => false]);

        Event::assertDispatched(AlertCommandRequested::class, fn ($e) => $e->command->payload['volume'] === 35
            && $e->command->payload['brightness'] === 60);
    }

    public function test_video_start_rule_starts_a_video_recording(): void
    {
        $this->rules([$this->flashRule('VIDEO_START', ['motion' => 'PICKED_UP'])]);

        $this->report(['motion' => 'PICKED_UP', 'video_recording' => false]);

        $this->assertDatabaseHas('device_commands', ['device_id' => $this->device->id, 'command' => 'START_VIDEO']);
        $this->assertDatabaseHas('recordings', ['device_id' => $this->device->id, 'media_kind' => 'VIDEO']);
    }

    public function test_video_start_rule_also_turns_on_the_flash_when_asked(): void
    {
        $rule = $this->flashRule('VIDEO_START', ['motion' => 'PICKED_UP']);
        $rule['with_flash'] = true;
        $this->rules([$rule]);

        $this->report(['motion' => 'PICKED_UP', 'video_recording' => false, 'flash_on' => false]);

        $this->assertDatabaseHas('device_commands', ['device_id' => $this->device->id, 'command' => 'START_VIDEO']);
        $this->assertDatabaseHas('device_commands', ['device_id' => $this->device->id, 'command' => 'FLASH_ON']);
        Event::assertDispatched(FlashCommandRequested::class);
    }

    public function test_video_start_rule_leaves_the_flash_alone_by_default(): void
    {
        $this->rules([$this->flashRule('VIDEO_START', ['motion' => 'PICKED_UP'])]);

        $this->report(['motion' => 'PICKED_UP', 'video_recording' => false, 'flash_on' => false]);

        $this->assertDatabaseMissing('device_commands', ['device_id' => $this->device->id, 'command' => 'FLASH_ON']);
    }

    public function test_video_start_rule_is_skipped_when_already_recording(): void
    {
        $this->rules([$this->flashRule('VIDEO_START', ['motion' => 'PICKED_UP'])]);

        $this->report(['motion' => 'PICKED_UP', 'video_recording' => true]);

        $this->assertDatabaseMissing('device_commands', ['device_id' => $this->device->id, 'command' => 'START_VIDEO']);
    }

    public function test_video_stop_rule_stops_the_active_video_recording(): void
    {
        app(RecordingLifecycleService::class)->startVideo($this->device->fresh());
        $this->rules([$this->flashRule('VIDEO_STOP', ['motion' => 'PUT_DOWN'])]);

        $this->report(['motion' => 'PUT_DOWN', 'video_recording' => true]);

        $this->assertDatabaseHas('device_commands', ['device_id' => $this->device->id, 'command' => 'STOP_VIDEO']);
    }

    public function test_dwell_rule_ignores_a_momentary_blip(): void
    {
        $rule = $this->flashRule('FLASH_ON', ['lux_op' => 'lt', 'lux_value' => 10]);
        $rule['when']['for_seconds'] = 3;
        $this->rules([$rule]);

        $this->travelTo('2026-10-08 10:00:00');
        $this->report(['lux' => 0, 'flash_on' => false]);   // a passing shadow
        Event::assertNotDispatched(FlashCommandRequested::class);

        $this->travelTo('2026-10-08 10:00:01');
        $this->report(['lux' => 200, 'flash_on' => false]); // recovered within 1s
        Event::assertNotDispatched(FlashCommandRequested::class);
    }

    public function test_dwell_rule_fires_once_the_condition_holds_long_enough(): void
    {
        $rule = $this->flashRule('FLASH_ON', ['lux_op' => 'lt', 'lux_value' => 10]);
        $rule['when']['for_seconds'] = 3;
        $this->rules([$rule]);

        $this->travelTo('2026-10-08 10:00:00');
        $this->report(['lux' => 2, 'flash_on' => false]);
        Event::assertNotDispatched(FlashCommandRequested::class);

        $this->travelTo('2026-10-08 10:00:04'); // still dark 4s later
        $this->report(['lux' => 2, 'flash_on' => false]);
        Event::assertDispatchedTimes(FlashCommandRequested::class, 1);
    }

    public function test_dwell_backstop_fires_when_the_device_sends_no_further_report(): void
    {
        $rule = $this->flashRule('FLASH_ON', ['lux_op' => 'lt', 'lux_value' => 10]);
        $rule['when']['for_seconds'] = 3;
        $this->rules([$rule]);

        $this->travelTo('2026-10-08 10:00:00');
        $this->report(['lux' => 2, 'flash_on' => false]); // opens the window, no fire yet
        Event::assertNotDispatched(FlashCommandRequested::class);

        // The device goes quiet (dark and still). The scheduled backstop runs
        // after the window against the last reading and confirms the condition.
        $this->travelTo('2026-10-08 10:00:03');
        (new EvaluateDeviceSensorRules($this->device->id))->handle(app(SensorRuleEngine::class));
        Event::assertDispatchedTimes(FlashCommandRequested::class, 1);
    }

    public function test_scheduled_rule_only_fires_within_its_day_and_time_window(): void
    {
        $rule = $this->flashRule('FLASH_ON', ['motion' => 'PICKED_UP']);
        $rule['when']['days'] = [0]; // Sunday
        $rule['when']['time_from'] = '04:00';
        $rule['when']['time_to'] = '05:00';
        $this->rules([$rule]);

        // Sunday (2026-10-11), but 06:00 — right day, outside the window.
        $this->travelTo('2026-10-11 06:00:00');
        $this->report(['motion' => 'PICKED_UP', 'flash_on' => false]);
        Event::assertNotDispatched(FlashCommandRequested::class);

        // Sunday 04:30 — inside the window.
        $this->travelTo('2026-10-11 04:30:00');
        $this->report(['motion' => 'PICKED_UP', 'flash_on' => false]);
        Event::assertDispatchedTimes(FlashCommandRequested::class, 1);
    }

    public function test_scheduled_rule_skips_the_wrong_weekday(): void
    {
        $rule = $this->flashRule('FLASH_ON', ['motion' => 'PICKED_UP']);
        $rule['when']['days'] = [0]; // Sunday only
        $this->rules([$rule]);

        $this->travelTo('2026-10-12 04:30:00'); // Monday
        $this->report(['motion' => 'PICKED_UP', 'flash_on' => false]);
        Event::assertNotDispatched(FlashCommandRequested::class);
    }

    public function test_rules_saved_from_the_dashboard_are_evaluated(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $this->postJson("/api/devices/{$this->device->id}/sensor-rules", [
            'rules' => [$this->flashRule('FLASH_ON', ['proximity' => 'near'])],
        ])->assertOk();

        // Same engine the /devices/sensors endpoint runs, fed the rules in
        // exactly the shape the dashboard endpoint stored them.
        $fired = app(SensorRuleEngine::class)->evaluate($this->device->fresh(), ['proximity_near' => true, 'flash_on' => false]);

        $this->assertSame(['FLASH_ON'], $fired);

        Event::assertDispatchedTimes(FlashCommandRequested::class, 1);
    }
}
