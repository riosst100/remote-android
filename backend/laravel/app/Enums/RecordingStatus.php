<?php

namespace App\Enums;

enum RecordingStatus: string
{
    case PENDING = 'PENDING';
    case STARTING = 'STARTING';
    case RECORDING = 'RECORDING';
    case STOPPING = 'STOPPING';
    case PROCESSING = 'PROCESSING';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::FAILED], true);
    }
}
