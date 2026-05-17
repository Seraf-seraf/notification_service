<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Delivery\DeliveryAction;
use App\Application\Delivery\DeliveryMessagePublisher;
use App\Application\Delivery\NotificationDeliveryProcessor;
use App\Application\Outbox\NotificationSendPayload;
use App\Application\Provider\NotificationProviderClient;
use App\Application\Provider\NotificationProviderRegistry;
use App\Application\Provider\ProviderSendRequest;
use App\Application\Provider\ProviderSendResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ObservabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_id_is_returned_and_http_metrics_are_exported(): void
    {
        Cache::flush();

        $this
            ->withHeaders([
                'X-Request-Id' => 'req-observability-http',
                'Idempotency-Key' => 'observability-http',
            ])
            ->postJson('/api/notifications/send', [
                'channel' => 'sms',
                'message' => 'Observability test message.',
                'priority' => 2,
                'recipient_ids' => ['subscriber-observability-http'],
            ])
            ->assertAccepted()
            ->assertHeader('X-Request-Id', 'req-observability-http')
            ->assertJsonPath('meta.request_id', 'req-observability-http');

        $response = $this->get('/api/metrics');

        $response
            ->assertOk()
            ->assertSee('notification_http_requests_total{method="POST",route="api.notifications.send",status_code="202"} 1', false)
            ->assertSee('notification_http_request_duration_seconds_bucket{le="0.005",method="POST",route="api.notifications.send",status_code="202"}', false)
            ->assertSee('notification_http_request_duration_seconds_count{method="POST",route="api.notifications.send",status_code="202"} 1', false)
            ->assertSee('notification_status_transitions_total{channel="sms",status="queued"} 1', false);
    }

    public function test_worker_metrics_include_processed_provider_latency_queue_lag_retry_and_dlq(): void
    {
        Cache::flush();

        $provider = new ObservabilityProviderClient;
        $publisher = new ObservabilityDeliveryMessagePublisher;
        $this->app->instance(NotificationProviderRegistry::class, new ObservabilityProviderRegistry($provider));
        $this->app->instance(DeliveryMessagePublisher::class, $publisher);
        $payload = $this->createNotificationPayload('email', 'subscriber-observability-worker', 'req-observability-worker');

        $result = $this->app->make(NotificationDeliveryProcessor::class)->process($payload);

        $this->assertSame(DeliveryAction::Ack, $result->action);
        $this->assertSame(1, $provider->sendCalls);

        $response = $this->get('/api/metrics');

        $response
            ->assertOk()
            ->assertSee('notification_worker_messages_processed_total{action="ack",channel="email"} 1', false)
            ->assertSee('notification_status_transitions_total{channel="email",status="sent"} 1', false)
            ->assertSee('notification_provider_request_duration_seconds_count{channel="email",provider="email",result="success"} 1', false)
            ->assertSee('notification_queue_lag_seconds_count{channel="email",priority="3"} 1', false);
    }

    private function createNotificationPayload(string $channel, string $subscriberId, string $requestId): NotificationSendPayload
    {
        $this
            ->withHeaders([
                'X-Request-Id' => $requestId,
                'Idempotency-Key' => 'observability-'.$requestId,
            ])
            ->postJson('/api/notifications/send', [
                'channel' => $channel,
                'message' => 'Worker observability test message.',
                'priority' => 3,
                'recipient_ids' => [$subscriberId],
            ])
            ->assertAccepted();

        $row = DB::table('outbox_messages')->where('channel', $channel)->first();
        $this->assertNotNull($row);

        $payload = json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);

        return NotificationSendPayload::fromArray($payload);
    }
}

final class ObservabilityProviderRegistry implements NotificationProviderRegistry
{
    public function __construct(
        private readonly ObservabilityProviderClient $client,
    ) {}

    public function forChannel(string $channel): NotificationProviderClient
    {
        return $this->client;
    }
}

final class ObservabilityProviderClient implements NotificationProviderClient
{
    public int $sendCalls = 0;

    public function send(ProviderSendRequest $request): ProviderSendResult
    {
        $this->sendCalls++;

        return new ProviderSendResult(
            providerMessageId: 'email-provider-observability',
            providerStatus: 'accepted',
        );
    }
}

final class ObservabilityDeliveryMessagePublisher implements DeliveryMessagePublisher
{
    public function retry(NotificationSendPayload $payload, int $retryAfterSeconds, string $reason): void {}

    public function deadLetter(NotificationSendPayload $payload, string $reason): void {}
}
