<?php

namespace App\Models;

use App\Enums\DeviceStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

class Device extends Model
{
    use HasApiTokens, HasFactory;

    protected $fillable = [
        'device_uuid',
        'name',
        'manufacturer',
        'model',
        'android_version',
        'app_version',
        'capabilities',
        'status',
        'current_recording_id',
        'api_token_id',
        'last_seen_at',
        'schedules_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'status' => DeviceStatus::class,
            'last_seen_at' => 'datetime',
            'schedules_synced_at' => 'datetime',
        ];
    }

    public function recordings(): HasMany
    {
        return $this->hasMany(Recording::class);
    }

    public function commands(): HasMany
    {
        return $this->hasMany(DeviceCommand::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(RecordingSchedule::class);
    }

    public function isOnline(): bool
    {
        if (! $this->last_seen_at) {
            return false;
        }

        return $this->last_seen_at->gt(now()->subSeconds(config('recorder.heartbeat_timeout_seconds')));
    }
}
