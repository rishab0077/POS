<?php

return [
    'enabled' => env('AUDIT_LOGGING_ENABLED', true),

    'fail_closed' => env('AUDIT_LOGGING_FAIL_CLOSED', false),

    'redacted_value' => '[REDACTED]',
];
