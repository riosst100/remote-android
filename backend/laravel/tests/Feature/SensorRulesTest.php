<?php

namespace Tests\Feature;

use App\Enums\DeviceStatus;
use App\Events\AlertCommandRequested;
use App\Events\DeviceSensorsUpdated;
use App\Events\FlashCommandRequested;
use App\Models\Device;
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

    public function test_rules_saved_from_the_dashboard_are_evaluated(): void
    {
        $this->actingAs(\App\Models\User::factory()->create(), 'web');
        $this->postJson("/api/devices/{$this->device->id}/sensor-rules", [
            'rules' => [$this->flashRule('FLASH_ON', ['proximity' => 'near'])],
        ])->assertOk();

        $this->report(['proximity_near' => true, 'flash_on' => false]);

        Event::assertDispatchedTimes(FlashCommandRequested::class, 1);
    }
}
