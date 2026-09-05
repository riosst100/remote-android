<?php

namespace App\Models;

use App\Enums\CommandStatus;
use App\Enums\CommandType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceCommand extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'device_id',
        'recording_id',
        'command_id',
        'command',
        'payload',
        'status',
        'sent_at',
        'received_at',
        'completed_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'command' => CommandType::class,
            'status' => CommandStatus::class,
            'payload' => 'array',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['command_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'command_id';
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function recording(): BelongsTo
    {
        return $this->belongsTo(Recording::class);
    }
}
