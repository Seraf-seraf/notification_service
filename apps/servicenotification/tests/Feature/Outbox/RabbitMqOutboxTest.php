<?php

declare(strict_types=1);

namespace Tests\Feature\Outbox;

use App\Application\Outbox\MessagePublisher;
use App\Application\Outbox\OutboxMessage;
use App\Application\Outbox\OutboxPublisher;
use App\Infrastructure\Messaging\RabbitMqMessageFactory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;
use Tests\TestCase;

class RabbitMqOutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_outbox_publishes_persistent_messages_in_priority_order(): void
    {
        $publisher = new RecordingMessagePublisher;
        $this->app->instance(MessagePublisher::class, $publisher);

        $this->createNotification('sms', 1, 'subscriber-priority-low', 'req-priority-low');
        $this->createNotification('email', 2, 'subscriber-priority-medium', 'req-priority-medium');
        $this->createNotification('sms', 3, 'subscriber-priority-high', 'req-priority-high');

        $result = $this->app->make(OutboxPublisher::class)->publishPending(limit: 10);

        $this->assertSame(3, $result->selected);
        $this->assertSame(3, $result->published);
        $this->assertSame(0, $result->failed);
        $this->assertSame([3, 2, 1], array_map(
            static fn (OutboxMessage $message): int => $message->priority,
            $publisher->messages,
        ));

        $firstMessage = $publisher->messages[0];
        $this->assertSame('notifications.exchange', $firstMessage->exchange);
        $this->assertSame('notifications.sms.send', $firstMessage->routingKey);
        $this->assertSame('sms', $firstMessage->payload->channel);
        $this->assertSame(3, $firstMessage->payload->priority);
        $this->assertSame(1, $firstMessage->payload->attempt);
        $this->assertSame('req-priority-high', $firstMessage->payload->requestId);
        $this->assertSame('req-priority-high', $firstMessage->headers->requestId);

        $amqpMessage = (new RabbitMqMessageFactory)->make($firstMessage);
        $properties = $amqpMessage->get_properties();

        $this->assertSame(AMQPMessage::DELIVERY_MODE_PERSISTENT, $properties['delivery_mode']);
        $this->assertSame(3, $properties['priority']);
        $this->assertSame('application/json', $properties['content_type']);
        $this->assertSame($firstMessage->outboxMessageId, $properties['message_id']);
        $this->assertSame('req-priority-high', $properties['correlation_id']);

        $this->assertDatabaseCount('outbox_messages', 3);
        $this->assertSame(3, DB::table('outbox_messages')->where('status', 'published')->count());
    }

    public function test_outbox_keeps_failed_message_available_for_retry_with_backoff(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-17 00:00:00', 'UTC'));

        $failingPublisher = new RecordingMessagePublisher(fail: true);
        $this->app->instance(MessagePublisher::class, $failingPublisher);
        $this->createNotification('sms', 3, 'subscriber-retry', 'req-retry');

        $firstResult = $this->app->make(OutboxPublisher::class)->publishPending(limit: 1);

        $this->assertSame(1, $firstResult->failed);
        $this->assertDatabaseHas('outbox_messages', [
            'status' => 'failed',
            'publish_attempts' => 1,
        ]);

        $failedRow = DB::table('outbox_messages')->first();
        $this->assertNotNull($failedRow);
        $this->assertStringContainsString('RabbitMQ is temporarily unavailable', (string) $failedRow->last_error);
        $this->assertSame(
            '2026-05-17T00:00:30.000000Z',
            CarbonImmutable::parse($failedRow->available_at)->toJSON(),
        );

        DB::table('outbox_messages')->update([
            'available_at' => CarbonImmutable::parse('2026-05-17 00:00:31', 'UTC'),
        ]);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-17 00:00:31', 'UTC'));

        $retryPublisher = new RecordingMessagePublisher;
        $this->app->instance(MessagePublisher::class, $retryPublisher);

        $retryResult = $this->app->make(OutboxPublisher::class)->publishPending(limit: 1);

        $this->assertSame(1, $retryResult->published);
        $this->assertDatabaseHas('outbox_messages', [
            'status' => 'published',
            'publish_attempts' => 2,
            'last_error' => null,
        ]);

        CarbonImmutable::setTestNow();
    }

    private function createNotification(string $channel, int $priority, string $subscriberId, string $requestId): void
    {
        $this
            ->withHeaders([
                'X-Request-Id' => $requestId,
                'Idempotency-Key' => 'outbox-'.$requestId,
            ])
            ->postJson('/api/notifications/send', [
                'channel' => $channel,
                'message' => 'Outbox integration test message.',
                'priority' => $priority,
                'recipient_ids' => [$subscriberId],
            ])
            ->assertAccepted();
    }
}

final class RecordingMessagePublisher implements MessagePublisher
{
    public array $messages = [];

    public function __construct(
        private readonly bool $fail = false,
    ) {}

    public function publish(OutboxMessage $message): void
    {
        $this->messages[] = $message;

        if ($this->fail) {
            throw new RuntimeException('RabbitMQ is temporarily unavailable.');
        }
    }
}
