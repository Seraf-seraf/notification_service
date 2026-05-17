<?php

return [
    'idempotency_ttl_hours' => (int) env('IDEMPOTENCY_TTL_HOURS', 24),
    'max_attempts' => (int) env('NOTIFICATION_MAX_ATTEMPTS', 5),
    'provider_webhook_url' => env('PROVIDER_WEBHOOK_URL', ''),
    'retry_backoff_seconds' => array_map(
        static fn (string $value): int => (int) trim($value),
        explode(',', (string) env('NOTIFICATION_RETRY_BACKOFF_SECONDS', '30,120,300,900,1800')),
    ),
    'providers' => [
        'sms' => [
            'base_url' => env('SMS_PROVIDER_URL', 'http://smsprovider:8081'),
            'timeout_seconds' => (float) env('SMS_PROVIDER_TIMEOUT_SECONDS', 3),
        ],
        'email' => [
            'base_url' => env('EMAIL_PROVIDER_URL', 'http://emailprovider:8082'),
            'timeout_seconds' => (float) env('EMAIL_PROVIDER_TIMEOUT_SECONDS', 3),
        ],
    ],
];
