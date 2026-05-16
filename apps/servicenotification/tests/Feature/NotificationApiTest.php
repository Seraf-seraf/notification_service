<?php

declare(strict_types=1);

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $this->assertDatabaseCount('outbox_messages', 2);
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
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonPath('error.message', 'Переданы некорректные параметры запроса.')
            ->assertJsonPath('meta.request_id', 'req-validation-001')
            ->assertJsonStructure([
                'error' => [
                    'code',
                    'message',
                    'details' => [
                        'channel',
                        'message',
                        'priority',
                        'recipient_ids',
                    ],
                ],
                'meta' => [
                    'request_id',
                ],
            ]);
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

    public function test_it_returns_same_result_for_same_idempotency_key_and_payload_without_duplicates(): void
    {
        $payload = [
            'channel' => 'sms',
            'message' => 'Marketing campaign message.',
            'priority' => 1,
            'recipient_ids' => ['subscriber-idem-001', 'subscriber-idem-002'],
        ];

        $firstResponse = $this
            ->withHeaders([
                'X-Request-Id' => 'req-idempotency-first',
                'Idempotency-Key' => 'same-payload-key',
            ])
            ->postJson('/api/notifications/send', $payload);

        $firstResponse->assertAccepted();

        $secondResponse = $this
            ->withHeaders([
                'X-Request-Id' => 'req-idempotency-second',
                'Idempotency-Key' => 'same-payload-key',
            ])
            ->postJson('/api/notifications/send', $payload);

        $secondResponse
            ->assertAccepted()
            ->assertJsonPath('data.batch_id', $firstResponse->json('data.batch_id'))
            ->assertJsonPath('data.notifications.0.notification_id', $firstResponse->json('data.notifications.0.notification_id'))
            ->assertJsonPath('data.notifications.1.notification_id', $firstResponse->json('data.notifications.1.notification_id'))
            ->assertJsonPath('meta.idempotency_key', 'same-payload-key');

        $this->assertDatabaseCount('notification_batches', 1);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseCount('notification_status_history', 2);
        $this->assertDatabaseCount('outbox_messages', 2);
        $this->assertDatabaseCount('idempotency_keys', 1);
    }

    public function test_it_rejects_same_idempotency_key_with_different_payload(): void
    {
        $this
            ->withHeaders([
                'X-Request-Id' => 'req-idempotency-conflict-first',
                'Idempotency-Key' => 'conflicting-key',
            ])
            ->postJson('/api/notifications/send', [
                'channel' => 'email',
                'message' => 'First payload.',
                'priority' => 2,
                'recipient_ids' => ['subscriber-conflict-001'],
            ])
            ->assertAccepted();

        $response = $this
            ->withHeaders([
                'X-Request-Id' => 'req-idempotency-conflict-second',
                'Idempotency-Key' => 'conflicting-key',
            ])
            ->postJson('/api/notifications/send', [
                'channel' => 'email',
                'message' => 'Changed payload.',
                'priority' => 2,
                'recipient_ids' => ['subscriber-conflict-001'],
            ]);

        $response
            ->assertConflict()
            ->assertHeader('X-Request-Id', 'req-idempotency-conflict-second')
            ->assertJsonPath('error.code', 'idempotency_conflict')
            ->assertJsonPath('error.details.idempotency_key', 'conflicting-key')
            ->assertJsonPath('meta.request_id', 'req-idempotency-conflict-second');

        $this->assertDatabaseCount('notification_batches', 1);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('outbox_messages', 1);
        $this->assertDatabaseCount('idempotency_keys', 1);
    }

    public function test_it_returns_uniform_error_response_for_missing_routes(): void
    {
        $response = $this
            ->withHeader('X-Request-Id', 'req-missing-route')
            ->getJson('/api/missing-route');

        $response
            ->assertNotFound()
            ->assertHeader('X-Request-Id', 'req-missing-route')
            ->assertJsonPath('error.code', 'not_found')
            ->assertJsonPath('meta.request_id', 'req-missing-route')
            ->assertJsonStructure([
                'error' => [
                    'code',
                    'message',
                ],
                'meta' => [
                    'request_id',
                ],
            ]);
    }

    public function test_it_returns_full_ordered_subscriber_history_and_supports_filters(): void
    {
        $this
            ->withHeaders([
                'X-Request-Id' => 'req-history-filter-create-sms',
                'Idempotency-Key' => 'history-filter-sms',
            ])
            ->postJson('/api/notifications/send', [
                'channel' => 'sms',
                'message' => 'SMS status flow.',
                'priority' => 2,
                'recipient_ids' => ['subscriber-filter-001'],
            ])
            ->assertAccepted();

        $this
            ->withHeaders([
                'X-Request-Id' => 'req-history-filter-create-email',
                'Idempotency-Key' => 'history-filter-email',
            ])
            ->postJson('/api/notifications/send', [
                'channel' => 'email',
                'message' => 'Email status flow.',
                'priority' => 3,
                'recipient_ids' => ['subscriber-filter-001'],
            ])
            ->assertAccepted();

        $smsNotificationId = DB::table('notifications')
            ->where('subscriber_id', 'subscriber-filter-001')
            ->where('channel', 'sms')
            ->value('id');

        $now = CarbonImmutable::now('UTC');

        DB::table('notifications')
            ->where('id', $smsNotificationId)
            ->update([
                'status' => 'delivered',
                'provider_status' => 'delivered',
                'provider_message_id' => 'sms-provider-filter-001',
                'updated_at' => $now,
            ]);

        DB::table('notification_status_history')->insert([
            [
                'notification_id' => $smsNotificationId,
                'status' => 'sent',
                'provider' => 'sms_mock',
                'provider_status' => 'accepted',
                'reason' => 'Provider accepted message.',
                'changed_at' => $now->addSecond(),
                'created_at' => $now->addSecond(),
                'updated_at' => $now->addSecond(),
            ],
            [
                'notification_id' => $smsNotificationId,
                'status' => 'delivered',
                'provider' => 'sms_mock',
                'provider_status' => 'delivered',
                'reason' => 'Provider delivered message.',
                'changed_at' => $now->addSeconds(2),
                'created_at' => $now->addSeconds(2),
                'updated_at' => $now->addSeconds(2),
            ],
        ]);

        $response = $this
            ->withHeader('X-Request-Id', 'req-history-filter-read')
            ->getJson('/api/subscribers/subscriber-filter-001/notifications?channel=sms&status=delivered');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data.notifications')
            ->assertJsonPath('data.notifications.0.channel', 'sms')
            ->assertJsonPath('data.notifications.0.current_status', 'delivered')
            ->assertJsonPath('data.notifications.0.provider_message_id', 'sms-provider-filter-001')
            ->assertJsonPath('data.notifications.0.status_history.0.status', 'queued')
            ->assertJsonPath('data.notifications.0.status_history.1.status', 'sent')
            ->assertJsonPath('data.notifications.0.status_history.2.status', 'delivered');
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
