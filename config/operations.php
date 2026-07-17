<?php

$boolean = fn (string $key, bool $default = false): bool => filter_var(
    env($key, $default),
    FILTER_VALIDATE_BOOL,
    FILTER_NULL_ON_FAILURE
) ?? $default;

return [
    'backup' => [
        'enabled' => $boolean('BACKUP_ENABLED', false),
        'retention' => [
            'daily' => (int) env('BACKUP_RETENTION_DAILY', 7),
            'weekly' => (int) env('BACKUP_RETENTION_WEEKLY', 4),
            'monthly' => (int) env('BACKUP_RETENTION_MONTHLY', 6),
        ],
        'storage_path' => env('BACKUP_STORAGE_PATH') ?: storage_path('backups'),
        'offsite' => [
            'enabled' => $boolean('BACKUP_OFFSITE_ENABLED', false),
            'path' => env('BACKUP_OFFSITE_PATH'),
        ],
    ],

    'monitoring' => [
        'alert_email' => env('MONITORING_ALERT_EMAIL'),
        'failed_job_threshold' => (int) env('MONITORING_FAILED_JOB_THRESHOLD', 1),
        'disk_warning_percent' => (int) env('MONITORING_DISK_WARNING_PERCENT', 80),
        'disk_critical_percent' => (int) env('MONITORING_DISK_CRITICAL_PERCENT', 90),
        'print_station_offline_minutes' => (int) env('MONITORING_PRINT_STATION_OFFLINE_MINUTES', 5),
        'backup_max_age_hours' => (int) env('MONITORING_BACKUP_MAX_AGE_HOURS', 30),
        'alert_cooldown_minutes' => (int) env('MONITORING_ALERT_COOLDOWN_MINUTES', 60),
    ],
];
