<?php

namespace App\Enums;

enum CommandType: string
{
    case START_RECORDING = 'START_RECORDING';
    case STOP_RECORDING = 'STOP_RECORDING';
}
