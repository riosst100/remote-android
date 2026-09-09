<?php

use App\Http\Controllers\Api\DeviceCommandController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\DeviceErrorController;
use App\Http\Controllers\Api\DeviceRecordingController;
use App\Http\Controllers\Api\DeviceScheduleController;
use App\Http\Controllers\Api\RecordingChunkController;
use App\Http\Controllers\Api\RecordingCompletionController;
use App\Http\Controllers\Api\RecordingController;
use App\Http\Controllers\Broadcasting\BroadcastAuthController;
use Illuminate\Support\Facades\Route;

// Device registration is the one endpoint a brand-new device can reach
// without a token yet — it's how the device obtains its token.
Route::post('/devices/register', [DeviceController::class, 'register'])
    ->middleware('throttle:10,1');

// Broadcasting (Reverb) channel authorization — split by audience/guard.
Route::post('/broadcasting/auth', [BroadcastAuthController::class, 'admin'])
    ->middleware(['auth:sanctum']);
Route::post('/devices/broadcasting/auth', [BroadcastAuthController::class, 'device'])
    ->middleware(['device']);

// Device-authenticated endpoints (bearer token issued at registration).
Route::middleware('device')->group(function () {
    Route::post('/devices/heartbeat', [DeviceController::class, 'heartbeat']);
    Route::post('/devices/error', DeviceErrorController::class);
    Route::get('/devices/schedules', [DeviceScheduleController::class, 'index']);
    Route::post('/devices/recordings', [DeviceRecordingController::class, 'store'])
        ->middleware('throttle:30,1');

    Route::post('/recordings/{recording:uuid}/chunks', [RecordingChunkController::class, 'store'])
        ->middleware('throttle:120,1');
    Route::post('/recordings/{recording:uuid}/complete', RecordingCompletionController::class);
    Route::post('/commands/{command:command_id}/ack', [DeviceCommandController::class, 'acknowledge']);
});

// Admin-dashboard-authenticated endpoints (session/Sanctum user guard).
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/devices', [DeviceController::class, 'index']);
    Route::get('/devices/{device}', [DeviceController::class, 'show']);

    Route::post('/recordings/start', [RecordingController::class, 'start']);
    Route::post('/recordings/{recording:uuid}/stop', [RecordingController::class, 'stop']);
    Route::get('/recordings', [RecordingController::class, 'index']);
    Route::get('/recordings/{recording:uuid}', [RecordingController::class, 'show']);
});
