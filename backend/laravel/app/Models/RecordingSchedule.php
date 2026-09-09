<?php

namespace App\Models;

use App\Enums\RecordingPreset;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class RecordingSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'day_of_week',
        'time_of_day',
        'preset',
        'duration_minutes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'preset' => RecordingPreset::class,
            'is_active' => 'boolean',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * Human-readable day name for the schedule's day_of_week (0 = Sunday).
     */
    public function dayName(): string
    {
        return Carbon::create(2026, 1, 4 + $this->day_of_week)->format('l');
    }
}
