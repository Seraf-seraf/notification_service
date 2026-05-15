<?php

return [
    'host' => env('RABBITMQ_HOST', 'rabbitmq'),
    'port' => (int) env('RABBITMQ_PORT', 5672),
    'management_port' => (int) env('RABBITMQ_MANAGEMENT_PORT', 15672),
    'user' => env('RABBITMQ_USER', 'notification'),
    'password' => env('RABBITMQ_PASSWORD', 'notification'),
    'vhost' => env('RABBITMQ_VHOST', 'notification'),
    'prefetch' => (int) env('RABBITMQ_PREFETCH', 10),
    'exchange' => env('RABBITMQ_NOTIFICATIONS_EXCHANGE', 'notifications.exchange'),
    'queues' => [
        'sms' => env('RABBITMQ_SMS_QUEUE', 'notifications.sms.send'),
        'email' => env('RABBITMQ_EMAIL_QUEUE', 'notifications.email.send'),
        'retry' => env('RABBITMQ_RETRY_QUEUE', 'notifications.retry'),
        'dead_letter' => env('RABBITMQ_DLQ', 'notifications.dlq'),
    ],
    'priority' => [
        'min' => 1,
        'max' => 3,
    ],
];
