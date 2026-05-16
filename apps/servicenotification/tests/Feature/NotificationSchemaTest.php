<?php

declare(strict_types=1);

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL schema constraints are verified only on pgsql.');
        }
    }

    public function test_notification_schema_has_required_tables_and_columns(): void
    {
        $this->assertTrue(Schema::hasTable('notification_batches'));
        $this->assertTrue(Schema::hasTable('notifications'));
        $this->assertTrue(Schema::hasTable('notification_status_history'));
        $this->assertTrue(Schema::hasTable('idempotency_keys'));
        $this->assertTrue(Schema::hasTable('outbox_messages'));

        $this->assertTrue(Schema::hasColumns('notifications', [
            'id',
            'batch_id',
            'subscriber_id',
            'channel',
            'priority',
            'status',
            'provider',
            'provider_status',
            'provider_message_id',
            'created_at',
        ]));

        $this->assertTrue(Schema::hasColumns('idempotency_keys', [
            'endpoint',
            'idempotency_key',
            'payload_hash',
            'batch_id',
            'response_status',
            'response_body',
            'expires_at',
        ]));

        $this->assertTrue(Schema::hasColumns('outbox_messages', [
            'outbox_message_id',
            'batch_id',
            'notification_id',
            'message_type',
            'exchange',
            'routing_key',
            'payload',
            'status',
            'available_at',
        ]));
    }

    public function test_idempotency_key_is_unique_inside_endpoint(): void
    {
        $batchId = $this->insertBatch();
        $this->insertIdempotencyKey($batchId, 'same-key');

        $this->expectException(QueryException::class);

        $this->insertIdempotencyKey($batchId, 'same-key');
    }

    public function test_notification_is_unique_inside_batch_and_subscriber(): void
    {
        $batchId = $this->insertBatch();
        $this->insertNotification($batchId, 'subscriber-1');

        $this->expectException(QueryException::class);

        $this->insertNotification($batchId, 'subscriber-1');
    }

    public function test_provider_message_id_is_unique_when_present(): void
    {
        $firstBatchId = $this->insertBatch();
        $secondBatchId = $this->insertBatch();
        $this->insertNotification($firstBatchId, 'subscriber-1', 'provider-message-1');

        $this->expectException(QueryException::class);

        $this->insertNotification($secondBatchId, 'subscriber-2', 'provider-message-1');
    }

    public function test_outbox_message_id_is_unique(): void
    {
        $batchId = $this->insertBatch();
        $notificationId = $this->insertNotification($batchId, 'subscriber-1');
        $outboxMessageId = (string) Str::uuid();
        $this->insertOutboxMessage($batchId, $notificationId, $outboxMessageId);

        $this->expectException(QueryException::class);

        $this->insertOutboxMessage($batchId, $notificationId, $outboxMessageId);
    }

    private function insertBatch(): string
    {
        $now = CarbonImmutable::now('UTC');
        $batchId = (string) Str::uuid();

        DB::table('notification_batches')->insert([
            'id' => $batchId,
            'channel' => 'sms',
            'message' => 'Schema test message.',
            'priority' => 1,
            'recipients_count' => 1,
            'idempotency_key' => null,
            'request_id' => 'schema-test',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $batchId;
    }

    private function insertNotification(string $batchId, string $subscriberId, ?string $providerMessageId = null): string
    {
        $now = CarbonImmutable::now('UTC');
        $notificationId = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id' => $notificationId,
            'batch_id' => $batchId,
            'subscriber_id' => $subscriberId,
            'channel' => 'sms',
            'message' => 'Schema test message.',
            'priority' => 1,
            'status' => 'queued',
            'provider' => 'sms_mock',
            'provider_status' => null,
            'provider_message_id' => $providerMessageId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $notificationId;
    }

    private function insertIdempotencyKey(string $batchId, string $idempotencyKey): void
    {
        $now = CarbonImmutable::now('UTC');

        DB::table('idempotency_keys')->insert([
            'id' => (string) Str::uuid(),
            'endpoint' => '/api/notifications/send',
            'idempotency_key' => $idempotencyKey,
            'payload_hash' => str_repeat('a', 64),
            'batch_id' => $batchId,
            'response_status' => 202,
            'response_body' => json_encode(['data' => ['batch_id' => $batchId]], JSON_THROW_ON_ERROR),
            'expires_at' => $now->addDay(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function insertOutboxMessage(string $batchId, string $notificationId, string $outboxMessageId): void
    {
        $now = CarbonImmutable::now('UTC');

        DB::table('outbox_messages')->insert([
            'id' => (string) Str::uuid(),
            'outbox_message_id' => $outboxMessageId,
            'batch_id' => $batchId,
            'notification_id' => $notificationId,
            'message_type' => 'notification.send',
            'exchange' => 'notifications',
            'routing_key' => 'notifications.sms',
            'channel' => 'sms',
            'priority' => 1,
            'payload' => json_encode(['notification_id' => $notificationId], JSON_THROW_ON_ERROR),
            'headers' => null,
            'status' => 'pending',
            'publish_attempts' => 0,
            'last_error' => null,
            'available_at' => $now,
            'published_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
