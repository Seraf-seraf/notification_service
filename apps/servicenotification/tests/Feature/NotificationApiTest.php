<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_accepts_bulk_notification_send_request(): void
    {
        $response = $this
            ->withHeaders([
                'X-Request-Id' => 'req-feature-001',
                'Idempotency-Key' => 'bulk-feature-001',
            ])
            ->postJson('/api/notifications/send', [
                'channel' => 'sms',
                'message' => 'Route changed. Check your trip details.',
                'priority' => 3,
                'recipient_ids' => ['subscriber-10001', 'subscriber-10002'],
            ]);

        $response
            ->assertAccepted()
            ->assertHeader('X-Request-Id', 'req-feature-001')
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.channel', 'sms')
            ->assertJsonPath('data.priority', 3)
            ->assertJsonPath('data.recipients_count', 2)
            ->assertJsonPath('data.notifications.0.recipient_id', 'subscriber-10001')
            ->assertJsonPath('data.notifications.0.status', 'queued')
            ->assertJsonPath('meta.request_id', 'req-feature-001')
            ->assertJsonPath('meta.idempotency_key', 'bulk-feature-001');

        $this->assertDatabaseCount('notification_batches', 1);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseCount('notification_status_history', 2);
        $this->assertDatabaseHas('notifications', [
            'subscriber_id' => 'subscriber-10001',
            'status' => 'queued',
            'channel' => 'sms',
        ]);
    }

    public function test_it_validates_bulk_notification_send_request(): void
    {
        $response = $this
            ->withHeader('X-Request-Id', 'req-validation-001')
            ->postJson('/api/notifications/send', [
                'channel' => 'push',
                'message' => '',
                'priority' => 4,
                'recipient_ids' => [],
            ]);

        $response
            ->assertUnprocessable()
            ->assertHeader('X-Request-Id', 'req-validation-001')
            ->assertJsonValidationErrors(['channel', 'message', 'priority', 'recipient_ids']);
    }

    public function test_it_returns_subscriber_notification_history(): void
    {
        $this
            ->withHeaders([
                'X-Request-Id' => 'req-history-create',
                'Idempotency-Key' => 'bulk-history-001',
            ])
            ->postJson('/api/notifications/send', [
                'channel' => 'email',
                'message' => 'Your access code is 123456.',
                'priority' => 3,
                'recipient_ids' => ['subscriber-20001', 'subscriber-20002'],
            ])
            ->assertAccepted();

        $response = $this
            ->withHeader('X-Request-Id', 'req-history-read')
            ->getJson('/api/subscribers/subscriber-20001/notifications');

        $response
            ->assertOk()
            ->assertHeader('X-Request-Id', 'req-history-read')
            ->assertJsonPath('data.subscriber_id', 'subscriber-20001')
            ->assertJsonPath('data.notifications.0.channel', 'email')
            ->assertJsonPath('data.notifications.0.message', 'Your access code is 123456.')
            ->assertJsonPath('data.notifications.0.priority', 3)
            ->assertJsonPath('data.notifications.0.current_status', 'queued')
            ->assertJsonPath('data.notifications.0.status_history.0.status', 'queued')
            ->assertJsonPath('meta.request_id', 'req-history-read')
            ->assertJsonPath('meta.limit', 50)
            ->assertJsonPath('meta.next_cursor', null);
    }

    public function test_it_paginates_subscriber_notification_history_with_cursor(): void
    {
        foreach (['First message.', 'Second message.'] as $index => $message) {
            $this
                ->withHeaders([
                    'X-Request-Id' => 'req-history-page-create-'.$index,
                    'Idempotency-Key' => 'bulk-history-page-'.$index,
                ])
                ->postJson('/api/notifications/send', [
                    'channel' => 'sms',
                    'message' => $message,
                    'priority' => 1,
                    'recipient_ids' => ['subscriber-page-001'],
                ])
                ->assertAccepted();
        }

        $firstPage = $this
            ->withHeader('X-Request-Id', 'req-history-page-1')
            ->getJson('/api/subscribers/subscriber-page-001/notifications?limit=1');

        $firstPage
            ->assertOk()
            ->assertJsonCount(1, 'data.notifications')
            ->assertJsonPath('meta.limit', 1);

        $cursor = $firstPage->json('meta.next_cursor');
        $this->assertIsString($cursor);

        $secondPage = $this
            ->withHeader('X-Request-Id', 'req-history-page-2')
            ->getJson('/api/subscribers/subscriber-page-001/notifications?limit=1&cursor='.$cursor);

        $secondPage
            ->assertOk()
            ->assertJsonCount(1, 'data.notifications')
            ->assertJsonPath('meta.next_cursor', null);
    }
}
