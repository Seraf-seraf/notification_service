<?php

return [
    'metrics' => [
        'enabled' => (bool) env('METRICS_ENABLED', true),
        'path' => env('METRICS_PATH', '/metrics'),
        'victoriametrics_url' => env('VICTORIAMETRICS_URL', 'http://victoriametrics:8428'),
        'http_latency_buckets' => array_map(
            static fn (string $value): float => (float) trim($value),
            explode(',', (string) env('HTTP_LATENCY_BUCKETS', '0.005,0.01,0.025,0.05,0.1,0.25,0.5,1,2.5,5,10')),
        ),
        'provider_latency_buckets' => array_map(
            static fn (string $value): float => (float) trim($value),
            explode(',', (string) env('PROVIDER_LATENCY_BUCKETS', '0.01,0.025,0.05,0.1,0.25,0.5,1,2.5,5,10')),
        ),
        'queue_lag_buckets' => array_map(
            static fn (string $value): float => (float) trim($value),
            explode(',', (string) env('QUEUE_LAG_BUCKETS', '0.1,0.5,1,5,15,30,60,300,900')),
        ),
    ],
];
