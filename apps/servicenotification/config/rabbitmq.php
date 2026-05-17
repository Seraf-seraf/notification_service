<?php

return [
    'host' => env('RABBITMQ_HOST', 'rabbitmq'),
    'port' => (int) env('RABBITMQ_PORT', 5672),
    'management_port' => (int) env('RABBITMQ_MANAGEMENT_PORT', 15672),
    'user' => env('RABBITMQ_USER', 'guest'),
    'password' => env('RABBITMQ_PASSWORD', 'guest'),
    'vhost' => env('RABBITMQ_VHOST', 'notification'),
    'prefetch' => (int) env('RABBITMQ_PREFETCH', 10),
    'connection_timeout' => (float) env('RABBITMQ_CONNECTION_TIMEOUT', 3),
    'read_write_timeout' => (float) env('RABBITMQ_READ_WRITE_TIMEOUT', 3),
    'heartbeat' => (int) env('RABBITMQ_HEARTBEAT', 30),
    'publish_confirm_timeout' => (float) env('RABBITMQ_PUBLISH_CONFIRM_TIMEOUT', 5),
    'exchange' => env('RABBITMQ_NOTIFICATIONS_EXCHANGE', 'notifications.exchange'),
    'retry_exchange' => env('RABBITMQ_RETRY_EXCHANGE', 'notifications.retry.exchange'),
    'dead_letter_exchange' => env('RABBITMQ_DEAD_LETTER_EXCHANGE', 'notifications.dlx'),
    'queues' => [
        'sms' => env('RABBITMQ_SMS_QUEUE', 'notifications.sms.send'),
        'email' => env('RABBITMQ_EMAIL_QUEUE', 'notifications.email.send'),
        'retry' => env('RABBITMQ_RETRY_QUEUE', 'notifications.retry'),
        'dead_letter' => env('RABBITMQ_DLQ', 'notifications.dlq'),
    ],
    'routing_keys' => [
        'sms' => env('RABBITMQ_SMS_ROUTING_KEY', 'notifications.sms.send'),
        'email' => env('RABBITMQ_EMAIL_ROUTING_KEY', 'notifications.email.send'),
        'dead_letter' => env('RABBITMQ_DLQ_ROUTING_KEY', 'notifications.dlq'),
    ],
    'retry_backoff_seconds' => array_map(
        static fn (string $value): int => (int) trim($value),
        explode(',', (string) env('RABBITMQ_RETRY_BACKOFF_SECONDS', '30,120,300,900,1800')),
    ),
    'priority' => [
        'min' => 1,
        'max' => 3,
    ],
];
