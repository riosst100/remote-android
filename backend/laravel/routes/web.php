<?php

use App\Http\Controllers\Web\ApkDownloadController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\DeviceDashboardController;
use App\Http\Controllers\Web\LogDashboardController;
use App\Http\Controllers\Web\RecordingDashboardController;
use App\Http\Controllers\Web\RecordingScheduleController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));

// Intentionally public (no auth): this is the link handed to whoever is
// setting up a new recording device, not an admin-only dashboard page.
Route::get('/download', [ApkDownloadController::class, 'show'])->name('apk.download.page');
Route::get('/download/apk', [ApkDownloadController::class, 'download'])
    ->middleware('throttle:30,1')
    ->name('apk.download.file');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('login.attempt');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/devices', [DeviceDashboardController::class, 'index'])->name('devices.index');
    Route::get('/devices/{device}', [DeviceDashboardController::class, 'show'])->name('devices.show');

    Route::post('/devices/{device}/schedules', [RecordingScheduleController::class, 'store'])->name('devices.schedules.store');
    Route::post('/devices/{device}/schedules/{schedule}/toggle', [RecordingScheduleController::class, 'toggle'])->name('devices.schedules.toggle');
    Route::delete('/devices/{device}/schedules/{schedule}', [RecordingScheduleController::class, 'destroy'])->name('devices.schedules.destroy');

    Route::get('/recordings', [RecordingDashboardController::class, 'index'])->name('recordings.index');
    Route::get('/recordings/{recording:uuid}', [RecordingDashboardController::class, 'show'])->name('recordings.show');
    Route::get('/recordings/{recording:uuid}/download', [RecordingDashboardController::class, 'download'])->name('recordings.download');

    Route::get('/logs', LogDashboardController::class)->name('logs.index');
});
