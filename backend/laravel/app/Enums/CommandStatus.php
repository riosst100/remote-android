<?php

namespace App\Enums;

enum CommandStatus: string
{
    case PENDING = 'PENDING';
    case SENT = 'SENT';
    case ACKNOWLEDGED = 'ACKNOWLEDGED';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';
}
