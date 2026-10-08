<?php

namespace App\Enums;

enum CommandType: string
{
    case START_RECORDING = 'START_RECORDING';
    case STOP_RECORDING = 'STOP_RECORDING';
    case FLASH_ON = 'FLASH_ON';
    case FLASH_OFF = 'FLASH_OFF';
    case SHOW_ALERT = 'SHOW_ALERT';
    case DISMISS_ALERT = 'DISMISS_ALERT';
    case START_VIDEO = 'START_VIDEO';
    case STOP_VIDEO = 'STOP_VIDEO';
}
