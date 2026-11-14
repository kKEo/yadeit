<?php

return [
    // GET /health answers only to "Authorization: Bearer <this>". Unset, the address does not exist.
    'token' => env('HEALTH_TOKEN'),

    // A file the backup job touches after each successful run. Unset, backups are not part of the health report.
    'backup_heartbeat' => env('BACKUP_HEARTBEAT'),
    'backup_max_age_hours' => (int) env('BACKUP_MAX_AGE_HOURS', 36),

    // A queued job waiting longer than this means the worker is not running.
    'queue_max_wait_minutes' => (int) env('HEALTH_QUEUE_MAX_WAIT', 10),
];
