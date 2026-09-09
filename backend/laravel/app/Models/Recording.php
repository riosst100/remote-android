<?php

namespace App\Models;

use App\Enums\RecordingPreset;
use App\Enums\RecordingSource;
use App\Enums\RecordingStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recording extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'uuid',
        'device_id',
        'status',
        'preset',
        'source',
        'encoder',
        'sample_rate',
        'bitrate',
        'channels',
        'started_at',
        'stopped_at',
        'duration',
        'file_path',
        'file_size',
        'mime_type',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'status' => RecordingStatus::class,
            'preset' => RecordingPreset::class,
            'source' => RecordingSource::class,
            'started_at' => 'datetime',
            'stopped_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(RecordingChunk::class)->orderBy('chunk_number');
    }

    public function commands(): HasMany
    {
        return $this->hasMany(DeviceCommand::class);
    }
}
