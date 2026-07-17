<?php

use App\Enums\UserRole;

$csv = static fn (string $key, string $default = ''): array => array_values(array_filter(array_map(
    static fn (string $value): string => trim($value),
    explode(',', (string) env($key, $default))
)));

return [
    'hosts' => [
        'enforce_separation' => (bool) env('HOST_SEPARATION_ENFORCED', false),
        'public' => $csv('PUBLIC_APP_HOSTS', parse_url((string) env('APP_URL', ''), PHP_URL_HOST) ?: 'localhost'),
        'private' => $csv('PRIVATE_APP_HOSTS', parse_url((string) env('APP_URL', ''), PHP_URL_HOST) ?: 'localhost'),
        'print' => $csv('PRINT_SERVICE_HOSTS'),
    ],

    'private_access' => [
        'ip_restriction_enabled' => (bool) env('PRIVATE_IP_RESTRICTION_ENABLED', false),
        'allowed_ips' => $csv('PRIVATE_ALLOWED_IPS'),
        'emergency_ips' => $csv('PRIVATE_EMERGENCY_IPS'),
    ],

    'health' => [
        'public_enabled' => (bool) env('HEALTH_PUBLIC_ENABLED', true),
        'allowed_ips' => $csv('HEALTH_ALLOWED_IPS', '127.0.0.1,::1'),
    ],

    'headers' => [
        'enabled' => (bool) env('SECURITY_HEADERS_ENABLED', true),
        'x_content_type_options' => env('SECURITY_HEADER_X_CONTENT_TYPE_OPTIONS', 'nosniff'),
        'x_frame_options' => env('SECURITY_HEADER_X_FRAME_OPTIONS', 'SAMEORIGIN'),
        'referrer_policy' => env('SECURITY_HEADER_REFERRER_POLICY', 'strict-origin-when-cross-origin'),
        'permissions_policy' => env('SECURITY_HEADER_PERMISSIONS_POLICY', 'camera=(), microphone=(), geolocation=()'),
        'cross_origin_opener_policy' => env('SECURITY_HEADER_CROSS_ORIGIN_OPENER_POLICY', 'same-origin'),
        'hsts_enabled' => (bool) env('SECURITY_HSTS_ENABLED', env('APP_ENV') === 'production'),
        'hsts_value' => env('SECURITY_HSTS_VALUE', 'max-age=31536000; includeSubDomains'),
        'csp_report_only_enabled' => (bool) env('SECURITY_CSP_REPORT_ONLY_ENABLED', true),
        'csp_report_only' => env('SECURITY_CSP_REPORT_ONLY', "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; img-src 'self' data: blob:; font-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; connect-src 'self' http: https: ws: wss:"),
    ],

    'rate_limits' => [
        'api_per_minute' => (int) env('RATE_LIMIT_API_PER_MINUTE', 120),
        'print_station_per_minute' => (int) env('RATE_LIMIT_PRINT_STATION_PER_MINUTE', 120),
        'broadcast_auth_per_minute' => (int) env('RATE_LIMIT_BROADCAST_AUTH_PER_MINUTE', 60),
        'audit_export_per_minute' => (int) env('RATE_LIMIT_AUDIT_EXPORT_PER_MINUTE', 6),
    ],

    'mfa' => [
        'enabled' => filter_var(env('MFA_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'required_roles' => [
            UserRole::Admin->value,
            UserRole::Owner->value,
            UserRole::Manager->value,
        ],
        'issuer' => env('MFA_ISSUER', env('APP_NAME', 'Restaurant POS')),
        'period' => 30,
        'digits' => 6,
        'recovery_codes' => 8,
    ],

    'step_up' => [
        'timeout' => (int) env('STEP_UP_TIMEOUT', 600),
    ],

    'print_service' => [
        'authentication_required' => (bool) env('PRINT_SERVICE_AUTH_REQUIRED', false),
        'client_id' => env('PRINT_SERVICE_CLIENT_ID'),
        'client_secret' => env('PRINT_SERVICE_CLIENT_SECRET'),
    ],
];
