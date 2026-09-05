<?php

namespace App\Enums;

enum DeviceStatus: string
{
    case ONLINE = 'ONLINE';
    case OFFLINE = 'OFFLINE';
    case RECORDING = 'RECORDING';
    case ERROR = 'ERROR';
}
