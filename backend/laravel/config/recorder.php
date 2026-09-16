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

    // Seconds a recording may sit in STOPPING/PROCESSING with no progress
    // (no new chunk, no ack) before it's considered abandoned and auto-failed.
    // Guards against a missed STOP_RECORDING/ack over the websocket leaving
    // a recording stuck forever with no retry path.
    'stuck_recording_timeout_seconds' => env('RECORDER_STUCK_RECORDING_TIMEOUT', 1800),
];
