<?php

declare(strict_types=1);

namespace Tests\Feature\Worker;

use App\Application\Delivery\DeliveryAction;
use App\Application\Delivery\DeliveryMessagePublisher;
use App\Application\Delivery\NotificationDeliveryProcessor;
use App\Application\Outbox\NotificationSendPayload;
use App\Application\Provider\NotificationProviderClient;
use App\Application\Provider\NotificationProviderRegistry;
use App\Application\Provider\PermanentProviderException;
use App\Application\Provider\ProviderSendRequest;
use App\Application\Provider\ProviderSendResult;
use App\Application\Provider\TemporaryProviderException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotificationDeliveryWorkerTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_sends_queued_notification_to_provider_and_is_idempotent_on_redelivery(): void
    {
        $provider = new FakeProviderClient(new ProviderSendResult(
            providerMessageId: 'sms-provider-000001',
            providerStatus: 'accepted',
        ));
        $publisher = new FakeDeliveryMessagePublisher;
        $this->bindDeliveryFakes($provider, $publisher);
        $payload = $this->createNotificationPayload('sms', 'subscriber-worker-success', 'req-worker-success');

        $firstResult = $this->app->make(NotificationDeliveryProcessor::class)->process($payload);
        $secondResult = $this->app->make(NotificationDeliveryProcessor::class)->process($payload);

        $this->assertSame(DeliveryAction::Ack, $firstResult->action);
        $this->assertSame(DeliveryAction::Ack, $secondResult->action);
        $this->assertSame(1, $provider->sendCalls);
        $this->assertSame([], $publisher->retries);
        $this->assertSame([], $publisher->deadLetters);
        $this->assertDatabaseHas('notifications', [
            'id' => $payload->notificationId,
            'status' => 'sent',
            'provider_status' => 'accepted',
            'provider_message_id' => 'sms-provider-000001',
        ]);
        $this->assertSame(
            ['queued', 'sent'],
            DB::table('notification_status_history')
                ->where('notification_id', $payload->notificationId)
                ->orderBy('id')
                ->pluck('status')
                ->all(),
        );
    }

    public function test_worker_drops_notification_without_retry_on_permanent_provider_error(): void
    {
        $provider = new FakeProviderClient(new PermanentProviderException('Recipient does not exist.'));
        $publisher = new FakeDeliveryMessagePublisher;
        $this->bindDeliveryFakes($provider, $publisher);
        $payload = $this->createNotificationPayload('email', 'subscriber-permanent-failure', 'req-worker-permanent');

        $result = $this->app->make(NotificationDeliveryProcessor::class)->process($payload);

        $this->assertSame(DeliveryAction::Ack, $result->action);
        $this->assertSame(1, $provider->sendCalls);
        $this->assertSame([], $publisher->retries);
        $this->assertSame([], $publisher->deadLetters);
        $this->assertDatabaseHas('notifications', [
            'id' => $payload->notificationId,
            'status' => 'dropped',
            'provider_status' => 'failed',
        ]);
    }

    public function test_worker_retries_temporary_errors_and_dead_letters_after_attempt_limit(): void
    {
        config(['notification.max_attempts' => 2]);

        $provider = new FakeProviderClient(new TemporaryProviderException('Provider is unavailable.'));
        $publisher = new FakeDeliveryMessagePublisher;
        $this->bindDeliveryFakes($provider, $publisher);
        $payload = $this->createNotificationPayload('sms', 'subscriber-temporary-failure', 'req-worker-retry');

        $firstResult = $this->app->make(NotificationDeliveryProcessor::class)->process($payload);
        $secondResult = $this->app->make(NotificationDeliveryProcessor::class)->process(new NotificationSendPayload(
            notificationId: $payload->notificationId,
            batchId: $payload->batchId,
            channel: $payload->channel,
            priority: $payload->priority,
            attempt: 2,
            requestId: $payload->requestId,
        ));

        $this->assertSame(DeliveryAction::Retry, $firstResult->action);
        $this->assertSame(DeliveryAction::DeadLetter, $secondResult->action);
        $this->assertCount(1, $publisher->retries);
        $this->assertSame(30, $publisher->retries[0]['retry_after_seconds']);
        $this->assertCount(1, $publisher->deadLetters);
        $this->assertDatabaseHas('notifications', [
            'id' => $payload->notificationId,
            'status' => 'dropped',
            'provider_status' => 'temporary_failed',
        ]);
    }

    public function test_provider_webhook_updates_delivery_status_monotonically(): void
    {
        $provider = new FakeProviderClient(new ProviderSendResult(
            providerMessageId: 'sms-provider-webhook-001',
            providerStatus: 'accepted',
        ));
        $this->bindDeliveryFakes($provider, new FakeDeliveryMessagePublisher);
        $payload = $this->createNotificationPayload('sms', 'subscriber-webhook', 'req-worker-webhook');

        $this->app->make(NotificationDeliveryProcessor::class)->process($payload);

        $this
            ->withHeader('X-Request-Id', 'req-webhook-delivered')
            ->postJson('/api/providers/sms/webhooks', [
                'provider_message_id' => 'sms-provider-webhook-001',
                'message_id' => $payload->notificationId,
                'status' => 'delivered',
                'reason' => null,
                'occurred_at' => '2026-05-17T10:00:00Z',
            ])
            ->assertAccepted()
            ->assertJsonPath('data.notification_status', 'delivered')
            ->assertJsonPath('data.applied', true);

        $this
            ->withHeader('X-Request-Id', 'req-webhook-stale')
            ->postJson('/api/providers/sms/webhooks', [
                'provider_message_id' => 'sms-provider-webhook-001',
                'message_id' => $payload->notificationId,
                'status' => 'processing',
                'reason' => 'Late stale provider event.',
                'occurred_at' => '2026-05-17T10:00:01Z',
            ])
            ->assertAccepted()
            ->assertJsonPath('data.notification_status', 'delivered')
            ->assertJsonPath('data.applied', false);

        $this->assertDatabaseHas('notifications', [
            'id' => $payload->notificationId,
            'status' => 'delivered',
            'provider_status' => 'delivered',
        ]);
        $this->assertSame(
            ['queued', 'sent', 'delivered'],
            DB::table('notification_status_history')
                ->where('notification_id', $payload->notificationId)
                ->orderBy('id')
                ->pluck('status')
                ->all(),
        );
    }

    private function bindDeliveryFakes(FakeProviderClient $provider, FakeDeliveryMessagePublisher $publisher): void
    {
        $this->app->instance(NotificationProviderRegistry::class, new FakeProviderRegistry($provider));
        $this->app->instance(DeliveryMessagePublisher::class, $publisher);
    }

    private function createNotificationPayload(string $channel, string $subscriberId, string $requestId): NotificationSendPayload
    {
        $this
            ->withHeaders([
                'X-Request-Id' => $requestId,
                'Idempotency-Key' => 'worker-'.$requestId,
            ])
            ->postJson('/api/notifications/send', [
                'channel' => $channel,
                'message' => 'Worker integration test message.',
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

final class FakeProviderRegistry implements NotificationProviderRegistry
{
    public function __construct(
        private readonly FakeProviderClient $client,
    ) {}

    public function forChannel(string $channel): NotificationProviderClient
    {
        return $this->client;
    }
}

final class FakeProviderClient implements NotificationProviderClient
{
    public int $sendCalls = 0;

    public function __construct(
        private readonly ProviderSendResult|TemporaryProviderException|PermanentProviderException $result,
    ) {}

    public function send(ProviderSendRequest $request): ProviderSendResult
    {
        $this->sendCalls++;

        if ($this->result instanceof TemporaryProviderException || $this->result instanceof PermanentProviderException) {
            throw $this->result;
        }

        return $this->result;
    }
}

final class FakeDeliveryMessagePublisher implements DeliveryMessagePublisher
{
    public array $retries = [];

    public array $deadLetters = [];

    public function retry(NotificationSendPayload $payload, int $retryAfterSeconds, string $reason): void
    {
        $this->retries[] = [
            'payload' => $payload,
            'retry_after_seconds' => $retryAfterSeconds,
            'reason' => $reason,
        ];
    }

    public function deadLetter(NotificationSendPayload $payload, string $reason): void
    {
        $this->deadLetters[] = [
            'payload' => $payload,
            'reason' => $reason,
        ];
    }
}
