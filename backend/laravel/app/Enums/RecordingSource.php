<?php

namespace App\Enums;

enum RecordingSource: string
{
    case ADMIN = 'ADMIN';                     // RecordingLifecycleService::start (dashboard "start now")
    case SCHEDULE_DEVICE = 'SCHEDULE_DEVICE';  // device-initiated, DeviceRecordingService::create
}
