<?php

return [

    // Seconds after the last heartbeat before a device is considered OFFLINE.
    'heartbeat_timeout_seconds' => env('RECORDER_HEARTBEAT_TIMEOUT', 90),

    // Disk (see config/filesystems.php) recordings and chunks are stored on.
    // Swap to 's3'/'r2'/'minio' later without touching finalization logic.
    'storage_disk' => env('RECORDER_STORAGE_DISK', 'recordings'),

    // Target duration, in seconds, the Android agent should aim for per chunk.
    'chunk_target_seconds' => env('RECORDER_CHUNK_SECONDS', 20),

    // How long a device authentication token remains valid before rotation is recommended.
    'device_token_ttl_days' => env('RECORDER_DEVICE_TOKEN_TTL_DAYS', 365),
];
