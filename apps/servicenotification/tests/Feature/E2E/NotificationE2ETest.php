<?php

declare(strict_types=1);

namespace Tests\Feature\E2E;

use App\Application\Delivery\DeliveryAction;
use App\Application\Delivery\DeliveryMessagePublisher;
use App\Application\Delivery\NotificationDeliveryProcessor;
use App\Application\Outbox\MessagePublisher;
use App\Application\Outbox\NotificationSendPayload;
use App\Application\Outbox\OutboxMessage;
use App\Application\Outbox\OutboxPublisher;
use App\Application\Provider\NotificationProviderClient;
use App\Application\Provider\NotificationProviderRegistry;
use App\Application\Provider\PermanentProviderException;
use App\Application\Provider\ProviderSendRequest;
use App\Application\Provider\ProviderSendResult;
use App\Application\Provider\TemporaryProviderException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('e2e')]
class NotificationE2ETest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_sms_and_email_delivery_flow_updates_history_and_keeps_api_idempotent(): void
    {
        $providers = new E2EProviderRegistry([
            'sms' => new E2EProviderClient('smsmsg'),
            'email' => new E2EProviderClient('emailmsg'),
        ]);
        $deliveryPublisher = new E2EDeliveryMessagePublisher;
        $rabbitPublisher = new E2ERabbitMessagePublisher;

        $this->app->instance(NotificationProviderRegistry::class, $providers);
        $this->app->instance(DeliveryMessagePublisher::class, $deliveryPublisher);
        $this->app->instance(MessagePublisher::class, $rabbitPublisher);

        $lowPriority = $this
            ->withHeaders([
                'X-Request-Id' => 'req-e2e-sms',
                'Idempotency-Key' => 'e2e-sms-marketing-001',
            ])
            ->postJson('/api/notifications/send', [
                'channel' => 'sms',
                'message' => 'Marketing SMS campaign.',
                'priority' => 1,
                'recipient_ids' => ['subscriber-e2e-sms-001', 'subscriber-e2e-sms-002'],
            ]);

        $lowPriority->assertAccepted();

        $highPriority = $this
            ->withHeaders([
                'X-Request-Id' => 'req-e2e-email',
                'Idempotency-Key' => 'e2e-email-transactional-001',
            ])
            ->postJson('/api/notifications/send', [
                'channel' => 'email',
                'message' => 'Your access code is 441122.',
                'priority' => 3,
                'recipient_ids' => ['subscriber-e2e-email-001'],
            ]);

        $highPriority->assertAccepted();

        $this
            ->withHeaders([
                'X-Request-Id' => 'req-e2e-sms-duplicate',
                'Idempotency-Key' => 'e2e-sms-marketing-001',
            ])
            ->postJson('/api/notifications/send', [
                'channel' => 'sms',
                'message' => 'Marketing SMS campaign.',
                'priority' => 1,
                'recipient_ids' => ['subscriber-e2e-sms-001', 'subscriber-e2e-sms-002'],
            ])
            ->assertAccepted()
            ->assertJsonPath('data.batch_id', $lowPriority->json('data.batch_id'));

        $this->assertDatabaseCount('notification_batches', 2);
        $this->assertDatabaseCount('notifications', 3);
        $this->assertDatabaseCount('outbox_messages', 3);

        $publishResult = $this->app->make(OutboxPublisher::class)->publishPending(limit: 10);

        $this->assertSame(3, $publishResult->published);
        $this->assertSame([3, 1, 1], array_map(
            static fn (OutboxMessage $message): int => $message->priority,
            $rabbitPublisher->messages,
        ));

        foreach ($rabbitPublisher->messages as $message) {
            $result = $this->app->make(NotificationDeliveryProcessor::class)->process($message->payload);

            $this->assertSame(DeliveryAction::Ack, $result->action);
        }

        $this->assertSame(2, $providers->client('sms')->sendCalls);
        $this->assertSame(1, $providers->client('email')->sendCalls);
        $this->assertSame([], $deliveryPublisher->retries);
        $this->assertSame([], $deliveryPublisher->deadLetters);

        $this->deliverProviderStatuses();

        $history = $this
            ->withHeader('X-Request-Id', 'req-e2e-history')
            ->getJson('/api/subscribers/subscriber-e2e-email-001/notifications');

        $history
            ->assertOk()
            ->assertJsonPath('data.notifications.0.current_status', 'delivered')
            ->assertJsonPath('data.notifications.0.status_history.0.status', 'queued')
            ->assertJsonPath('data.notifications.0.status_history.1.status', 'sent')
            ->assertJsonPath('data.notifications.0.status_history.2.status', 'delivered');
    }

    public function test_provider_failures_use_retry_dead_letter_and_dropped_statuses(): void
    {
        config(['notification.max_attempts' => 2]);

        $providers = new E2EProviderRegistry([
            'sms' => new E2EProviderClient('smsmsg', new TemporaryProviderException('Provider temporarily unavailable.')),
            'email' => new E2EProviderClient('emailmsg', new PermanentProviderException('Recipient does not exist.')),
        ]);
        $deliveryPublisher = new E2EDeliveryMessagePublisher;
        $rabbitPublisher = new E2ERabbitMessagePublisher;

        $this->app->instance(NotificationProviderRegistry::class, $providers);
        $this->app->instance(DeliveryMessagePublisher::class, $deliveryPublisher);
        $this->app->instance(MessagePublisher::class, $rabbitPublisher);

        $this
            ->withHeaders([
                'X-Request-Id' => 'req-e2e-temporary',
                'Idempotency-Key' => 'e2e-temporary-failure-001',
            ])
            ->postJson('/api/notifications/send', [
                'channel' => 'sms',
                'message' => 'Temporary failure scenario.',
                'priority' => 3,
                'recipient_ids' => ['temporary-failure-recipient'],
            ])
            ->assertAccepted();

        $this
            ->withHeaders([
                'X-Request-Id' => 'req-e2e-permanent',
                'Idempotency-Key' => 'e2e-permanent-failure-001',
            ])
            ->postJson('/api/notifications/send', [
                'channel' => 'email',
                'message' => 'Permanent failure scenario.',
                'priority' => 2,
                'recipient_ids' => ['invalid-recipient'],
            ])
            ->assertAccepted();

        $this->app->make(OutboxPublisher::class)->publishPending(limit: 10);

        $temporaryPayload = $this->payloadByChannel($rabbitPublisher->messages, 'sms');
        $permanentPayload = $this->payloadByChannel($rabbitPublisher->messages, 'email');

        $retryResult = $this->app->make(NotificationDeliveryProcessor::class)->process($temporaryPayload);
        $deadLetterResult = $this->app->make(NotificationDeliveryProcessor::class)->process(new NotificationSendPayload(
            notificationId: $temporaryPayload->notificationId,
            batchId: $temporaryPayload->batchId,
            channel: $temporaryPayload->channel,
            priority: $temporaryPayload->priority,
            attempt: 2,
            requestId: $temporaryPayload->requestId,
        ));
        $permanentResult = $this->app->make(NotificationDeliveryProcessor::class)->process($permanentPayload);

        $this->assertSame(DeliveryAction::Retry, $retryResult->action);
        $this->assertSame(DeliveryAction::DeadLetter, $deadLetterResult->action);
        $this->assertSame(DeliveryAction::Ack, $permanentResult->action);
        $this->assertCount(1, $deliveryPublisher->retries);
        $this->assertCount(1, $deliveryPublisher->deadLetters);

        $this->assertDatabaseHas('notifications', [
            'id' => $temporaryPayload->notificationId,
            'status' => 'dropped',
            'provider_status' => 'temporary_failed',
        ]);
        $this->assertDatabaseHas('notifications', [
            'id' => $permanentPayload->notificationId,
            'status' => 'dropped',
            'provider_status' => 'failed',
        ]);
    }

    private function deliverProviderStatuses(): void
    {
        $rows = DB::table('notifications')
            ->where('status', 'sent')
            ->orderBy('id')
            ->get(['id', 'channel', 'provider_message_id']);

        foreach ($rows as $row) {
            $this
                ->withHeader('X-Request-Id', 'req-e2e-webhook-'.$row->id)
                ->postJson('/api/providers/'.$row->channel.'/webhooks', [
                    'provider_message_id' => $row->provider_message_id,
                    'message_id' => $row->id,
                    'status' => 'delivered',
                    'reason' => null,
                    'occurred_at' => now('UTC')->addSecond()->toJSON(),
                    'metadata' => [
                        'request_id' => 'req-e2e-webhook-'.$row->id,
                    ],
                ])
                ->assertAccepted()
                ->assertJsonPath('data.notification_status', 'delivered');
        }
    }

    private function payloadByChannel(array $messages, string $channel): NotificationSendPayload
    {
        foreach ($messages as $message) {
            if ($message->payload->channel === $channel) {
                return $message->payload;
            }
        }

        $this->fail('Payload for channel '.$channel.' was not published.');
    }
}

final class E2EProviderRegistry implements NotificationProviderRegistry
{
    public function __construct(
        private readonly array $clients,
    ) {}

    public function forChannel(string $channel): NotificationProviderClient
    {
        return $this->client($channel);
    }

    public function client(string $channel): E2EProviderClient
    {
        return $this->clients[$channel];
    }
}

final class E2EProviderClient implements NotificationProviderClient
{
    public int $sendCalls = 0;

    public array $requests = [];

    public function __construct(
        private readonly string $messagePrefix,
        private readonly TemporaryProviderException|PermanentProviderException|null $exception = null,
    ) {}

    public function send(ProviderSendRequest $request): ProviderSendResult
    {
        $this->sendCalls++;
        $this->requests[] = $request;

        if ($this->exception !== null) {
            throw $this->exception;
        }

        return new ProviderSendResult(
            providerMessageId: $this->messagePrefix.'-'.str_pad((string) $this->sendCalls, 6, '0', STR_PAD_LEFT),
            providerStatus: 'accepted',
        );
    }
}

final class E2EDeliveryMessagePublisher implements DeliveryMessagePublisher
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

final class E2ERabbitMessagePublisher implements MessagePublisher
{
    public array $messages = [];

    public function publish(OutboxMessage $message): void
    {
        $this->messages[] = $message;
    }
}
