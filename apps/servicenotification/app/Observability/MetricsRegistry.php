<?php

declare(strict_types=1);

namespace App\Observability;

use Illuminate\Contracts\Cache\Repository;

final class MetricsRegistry
{
    private const PREFIX = 'metrics';

    public function __construct(
        private readonly Repository $cache,
    ) {}

    public function recordHttpRequest(string $method, string $route, int $statusCode, float $durationSeconds): void
    {
        if (! $this->enabled()) {
            return;
        }

        $labels = [
            'method' => strtoupper($method),
            'route' => $route,
            'status_code' => (string) $statusCode,
        ];

        $this->increment('notification_http_requests_total', $labels);
        $this->observeHistogram('notification_http_request_duration_seconds', $labels, $durationSeconds, $this->httpLatencyBuckets());
    }

    public function recordWorkerResult(string $channel, string $action): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->increment('notification_worker_messages_processed_total', [
            'channel' => $channel,
            'action' => $action,
        ]);

        if ($action === 'retry') {
            $this->increment('notification_worker_messages_retried_total', ['channel' => $channel]);
        }

        if ($action === 'dead_letter') {
            $this->increment('notification_worker_messages_dropped_total', ['channel' => $channel]);
        }
    }

    public function recordWorkerFailure(string $channel, string $reason): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->increment('notification_worker_messages_failed_total', [
            'channel' => $channel,
            'reason' => $this->normalizeLabelValue($reason),
        ]);
    }

    public function recordNotificationStatus(string $channel, string $status, int $count = 1): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->increment('notification_status_transitions_total', [
            'channel' => $channel,
            'status' => $status,
        ], $count);
    }

    public function observeProviderLatency(string $channel, string $provider, string $result, float $durationSeconds): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->observeHistogram('notification_provider_request_duration_seconds', [
            'channel' => $channel,
            'provider' => $provider,
            'result' => $result,
        ], $durationSeconds, $this->providerLatencyBuckets());
    }

    public function observeQueueLag(string $channel, int $priority, float $lagSeconds): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->observeHistogram('notification_queue_lag_seconds', [
            'channel' => $channel,
            'priority' => (string) $priority,
        ], max(0.0, $lagSeconds), $this->queueLagBuckets());
    }

    public function render(): string
    {
        $lines = [
            '# HELP notification_http_requests_total Total HTTP requests handled by notification service.',
            '# TYPE notification_http_requests_total counter',
        ];

        $lines = array_merge($lines, $this->renderCounters('notification_http_requests_total'));
        $lines[] = '# HELP notification_http_request_duration_seconds HTTP request latency histogram.';
        $lines[] = '# TYPE notification_http_request_duration_seconds histogram';
        $lines = array_merge($lines, $this->renderHistogram('notification_http_request_duration_seconds'));
        $lines[] = '# HELP notification_worker_messages_processed_total Worker messages processed by action.';
        $lines[] = '# TYPE notification_worker_messages_processed_total counter';
        $lines = array_merge($lines, $this->renderCounters('notification_worker_messages_processed_total'));
        $lines[] = '# HELP notification_worker_messages_failed_total Worker processing failures.';
        $lines[] = '# TYPE notification_worker_messages_failed_total counter';
        $lines = array_merge($lines, $this->renderCounters('notification_worker_messages_failed_total'));
        $lines[] = '# HELP notification_worker_messages_retried_total Worker messages sent to retry.';
        $lines[] = '# TYPE notification_worker_messages_retried_total counter';
        $lines = array_merge($lines, $this->renderCounters('notification_worker_messages_retried_total'));
        $lines[] = '# HELP notification_worker_messages_dropped_total Worker messages sent to DLQ or dropped.';
        $lines[] = '# TYPE notification_worker_messages_dropped_total counter';
        $lines = array_merge($lines, $this->renderCounters('notification_worker_messages_dropped_total'));
        $lines[] = '# HELP notification_status_transitions_total Notification status transitions by channel.';
        $lines[] = '# TYPE notification_status_transitions_total counter';
        $lines = array_merge($lines, $this->renderCounters('notification_status_transitions_total'));
        $lines[] = '# HELP notification_provider_request_duration_seconds Provider request latency histogram.';
        $lines[] = '# TYPE notification_provider_request_duration_seconds histogram';
        $lines = array_merge($lines, $this->renderHistogram('notification_provider_request_duration_seconds'));
        $lines[] = '# HELP notification_queue_lag_seconds Time from notification creation to worker processing.';
        $lines[] = '# TYPE notification_queue_lag_seconds histogram';
        $lines = array_merge($lines, $this->renderHistogram('notification_queue_lag_seconds'));
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function enabled(): bool
    {
        return (bool) config('observability.metrics.enabled', true);
    }

    private function increment(string $metric, array $labels, int $value = 1): void
    {
        $key = $this->metricKey($metric, $labels, 'value');
        $this->rememberSeries($metric, $labels);
        $this->cache->increment($key, $value);
    }

    private function observeHistogram(string $metric, array $labels, float $value, array $buckets): void
    {
        $this->rememberSeries($metric, $labels);
        $this->cache->increment($this->metricKey($metric, $labels, 'count'));
        $this->cache->increment($this->metricKey($metric, $labels, 'sum_us'), max(0, (int) round($value * 1_000_000)));

        foreach ($buckets as $bucket) {
            if ($value <= $bucket) {
                $bucketLabels = $labels + ['le' => $this->formatBucket($bucket)];
                $this->cache->increment($this->metricKey($metric, $bucketLabels, 'bucket'));
            }
        }

        $this->cache->increment($this->metricKey($metric, $labels + ['le' => '+Inf'], 'bucket'));
    }

    private function rememberSeries(string $metric, array $labels): void
    {
        $series = $this->cache->get($this->seriesKey($metric), []);

        if (! is_array($series)) {
            $series = [];
        }

        $series[$this->labelsKey($labels)] = $labels;
        $this->cache->forever($this->seriesKey($metric), $series);
    }

    private function renderCounters(string $metric): array
    {
        $lines = [];

        foreach ($this->series($metric) as $labels) {
            $value = (int) $this->cache->get($this->metricKey($metric, $labels, 'value'), 0);
            $lines[] = $metric.$this->formatLabels($labels).' '.$value;
        }

        return $lines;
    }

    private function renderHistogram(string $metric): array
    {
        $lines = [];

        foreach ($this->series($metric) as $labels) {
            foreach ($this->histogramBuckets($metric) as $bucket) {
                $bucketLabels = $labels + ['le' => $bucket];
                $lines[] = $metric.'_bucket'.$this->formatLabels($bucketLabels).' '.(int) $this->cache->get($this->metricKey($metric, $bucketLabels, 'bucket'), 0);
            }

            $lines[] = $metric.'_sum'.$this->formatLabels($labels).' '.number_format(((int) $this->cache->get($this->metricKey($metric, $labels, 'sum_us'), 0)) / 1_000_000, 6, '.', '');
            $lines[] = $metric.'_count'.$this->formatLabels($labels).' '.(int) $this->cache->get($this->metricKey($metric, $labels, 'count'), 0);
        }

        return $lines;
    }

    private function series(string $metric): array
    {
        $series = $this->cache->get($this->seriesKey($metric), []);

        return is_array($series) ? array_values($series) : [];
    }

    private function metricKey(string $metric, array $labels, string $field): string
    {
        return self::PREFIX.':'.$metric.':'.$field.':'.sha1($this->labelsKey($labels));
    }

    private function seriesKey(string $metric): string
    {
        return self::PREFIX.':'.$metric.':series';
    }

    private function labelsKey(array $labels): string
    {
        ksort($labels);

        return json_encode($labels, JSON_THROW_ON_ERROR);
    }

    private function formatLabels(array $labels): string
    {
        if ($labels === []) {
            return '';
        }

        ksort($labels);
        $pairs = [];

        foreach ($labels as $name => $value) {
            $pairs[] = $name.'="'.str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], (string) $value).'"';
        }

        return '{'.implode(',', $pairs).'}';
    }

    private function normalizeLabelValue(string $value): string
    {
        return preg_replace('/[^a-zA-Z0-9_.-]+/', '_', mb_substr($value, 0, 64)) ?: 'unknown';
    }

    private function httpLatencyBuckets(): array
    {
        return array_map('floatval', (array) config('observability.metrics.http_latency_buckets', []));
    }

    private function providerLatencyBuckets(): array
    {
        return array_map('floatval', (array) config('observability.metrics.provider_latency_buckets', [0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10]));
    }

    private function queueLagBuckets(): array
    {
        return array_map('floatval', (array) config('observability.metrics.queue_lag_buckets', [0.1, 0.5, 1, 5, 15, 30, 60, 300, 900]));
    }

    private function histogramBuckets(string $metric): array
    {
        $buckets = match ($metric) {
            'notification_http_request_duration_seconds' => $this->httpLatencyBuckets(),
            'notification_provider_request_duration_seconds' => $this->providerLatencyBuckets(),
            'notification_queue_lag_seconds' => $this->queueLagBuckets(),
            default => [],
        };

        return array_merge(array_map(fn (float $bucket): string => $this->formatBucket($bucket), $buckets), ['+Inf']);
    }

    private function formatBucket(float $bucket): string
    {
        return rtrim(rtrim(number_format($bucket, 6, '.', ''), '0'), '.');
    }
}
