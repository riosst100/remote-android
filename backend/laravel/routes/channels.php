<?php

use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/**
 * Admin dashboard channels — authenticated web/Sanctum users only.
 */
Broadcast::channel('admin.devices', function (User $user) {
    return true;
}, ['guards' => ['web']]);

Broadcast::channel('admin.recordings', function (User $user) {
    return true;
}, ['guards' => ['web']]);

/**
 * Per-device channel. Reachable by an authenticated admin user (to watch
 * any device) or by the device itself (only its own channel). The two
 * broadcasting-auth endpoints (BroadcastAuthController@admin/@device)
 * authenticate against different guards — "web" for the dashboard,
 * "device" for the agent's bearer token — so `guards` here tells
 * Laravel's Broadcaster to try both and hand back whichever one resolves;
 * the callback then checks which kind of model it actually got.
 */
Broadcast::channel('devices.{deviceId}', function (User|Device $authenticatable, int $deviceId) {
    if ($authenticatable instanceof Device) {
        return $authenticatable->id === $deviceId;
    }

    return true;
}, ['guards' => ['web', 'device']]);

Broadcast::channel('recordings.{recordingUuid}', function (User $user, string $recordingUuid) {
    return true;
}, ['guards' => ['web']]);
