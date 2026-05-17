<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Command\SendNotificationsCommand;
use App\Application\DTO\NotificationQueuedDto;
use App\Application\DTO\SendNotificationsResultDto;
use App\Application\DTO\StatusHistoryItemDto;
use App\Application\DTO\SubscriberNotificationDto;
use App\Application\DTO\SubscriberNotificationsPageDto;
use App\Application\Exception\IdempotencyConflictException;
use App\Application\Query\ListSubscriberNotificationsQuery;
use App\Application\Repository\NotificationRepository;
use App\Application\Support\CursorCodec;
use App\Domain\Notification\NotificationProvider;
use App\Domain\Notification\NotificationStatus;
use App\Domain\Outbox\OutboxMessageStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DatabaseNotificationRepository implements NotificationRepository
{
    private const string SEND_ENDPOINT = '/api/notifications/send';

    private const string OUTBOX_MESSAGE_TYPE = 'notification.send';

    public function createBatch(SendNotificationsCommand $command): SendNotificationsResultDto
    {
        $now = CarbonImmutable::now('UTC');
        $batchId = (string) Str::uuid();
        $provider = NotificationProvider::fromChannel($command->channel);
        $payloadHash = $this->payloadHash($command);

        if ($command->idempotencyKey !== null) {
            $existing = $this->findActiveIdempotencyRecord($command, $now);

            if ($existing !== null) {
                if ($existing->payload_hash !== $payloadHash) {
                    throw new IdempotencyConflictException($command->idempotencyKey);
                }

                return $this->resultFromBatch((string) $existing->batch_id, $command);
            }
        }

        $queuedNotifications = DB::transaction(function () use ($command, $batchId, $now, $provider, $payloadHash): array {
            DB::table('notification_batches')->insert([
                'id' => $batchId,
                'channel' => $command->channel,
                'message' => $command->message,
                'priority' => $command->priority,
                'recipients_count' => count($command->recipientIds),
                'idempotency_key' => $command->idempotencyKey,
                'request_id' => $command->requestId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $notificationRows = [];
            $historyRows = [];
            $outboxRows = [];
            $queuedNotifications = [];

            foreach ($command->recipientIds as $recipientId) {
                $notificationId = (string) Str::uuid();
                $outboxMessageId = (string) Str::uuid();

                $notificationRows[] = [
                    'id' => $notificationId,
                    'batch_id' => $batchId,
                    'subscriber_id' => $recipientId,
                    'channel' => $command->channel,
                    'message' => $command->message,
                    'priority' => $command->priority,
                    'status' => NotificationStatus::Queued->value,
                    'provider' => $provider->value,
                    'provider_status' => null,
                    'provider_message_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $historyRows[] = [
                    'notification_id' => $notificationId,
                    'status' => NotificationStatus::Queued->value,
                    'provider' => $provider->value,
                    'provider_status' => null,
                    'reason' => 'Notification accepted and waiting for dispatch.',
                    'changed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $outboxRows[] = [
                    'id' => (string) Str::uuid(),
                    'outbox_message_id' => $outboxMessageId,
                    'batch_id' => $batchId,
                    'notification_id' => $notificationId,
                    'message_type' => self::OUTBOX_MESSAGE_TYPE,
                    'exchange' => config('rabbitmq.exchange', 'notifications.exchange'),
                    'routing_key' => config('rabbitmq.routing_keys.'.$command->channel, 'notifications.'.$command->channel.'.send'),
                    'channel' => $command->channel,
                    'priority' => $command->priority,
                    'payload' => json_encode([
                        'notification_id' => $notificationId,
                        'batch_id' => $batchId,
                        'channel' => $command->channel,
                        'priority' => $command->priority,
                        'attempt' => 1,
                        'request_id' => $command->requestId,
                    ], JSON_THROW_ON_ERROR),
                    'headers' => json_encode([
                        'X-Request-Id' => $command->requestId,
                        'Idempotency-Key' => $command->idempotencyKey,
                    ], JSON_THROW_ON_ERROR),
                    'status' => OutboxMessageStatus::Pending->value,
                    'publish_attempts' => 0,
                    'last_error' => null,
                    'available_at' => $now,
                    'published_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $queuedNotifications[] = new NotificationQueuedDto(
                    notificationId: $notificationId,
                    recipientId: $recipientId,
                    status: NotificationStatus::Queued->value,
                );
            }

            DB::table('notifications')->insert($notificationRows);
            DB::table('notification_status_history')->insert($historyRows);
            DB::table('outbox_messages')->insert($outboxRows);

            if ($command->idempotencyKey !== null) {
                DB::table('idempotency_keys')->insert([
                    'id' => (string) Str::uuid(),
                    'endpoint' => self::SEND_ENDPOINT,
                    'idempotency_key' => $command->idempotencyKey,
                    'payload_hash' => $payloadHash,
                    'batch_id' => $batchId,
                    'response_status' => 202,
                    'response_body' => json_encode(['batch_id' => $batchId], JSON_THROW_ON_ERROR),
                    'expires_at' => $now->addHours((int) config('notification.idempotency_ttl_hours', 24)),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return $queuedNotifications;
        });

        return new SendNotificationsResultDto(
            batchId: $batchId,
            status: 'accepted',
            channel: $command->channel,
            priority: $command->priority,
            message: $command->message,
            recipientsCount: count($command->recipientIds),
            notifications: $queuedNotifications,
            requestId: $command->requestId,
            idempotencyKey: $command->idempotencyKey,
            createdAt: $now->toJSON(),
        );
    }

    private function findActiveIdempotencyRecord(SendNotificationsCommand $command, CarbonImmutable $now): ?object
    {
        if ($command->idempotencyKey === null) {
            return null;
        }

        $record = DB::table('idempotency_keys')
            ->where('endpoint', self::SEND_ENDPOINT)
            ->where('idempotency_key', $command->idempotencyKey)
            ->where('expires_at', '>', $now)
            ->first();

        return is_object($record) ? $record : null;
    }

    private function payloadHash(SendNotificationsCommand $command): string
    {
        $payload = [
            'channel' => $command->channel,
            'message' => $command->message,
            'priority' => $command->priority,
            'recipient_ids' => array_values($command->recipientIds),
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function resultFromBatch(string $batchId, SendNotificationsCommand $command): SendNotificationsResultDto
    {
        $batch = DB::table('notification_batches')->where('id', $batchId)->first();

        if (! is_object($batch)) {
            throw new IdempotencyConflictException((string) $command->idempotencyKey);
        }

        $notifications = DB::table('notifications')
            ->where('batch_id', $batchId)
            ->get()
            ->keyBy('subscriber_id');

        $queuedNotifications = collect($command->recipientIds)
            ->map(fn (string $recipientId): ?object => $notifications->get($recipientId))
            ->filter()
            ->map(fn (object $notification): NotificationQueuedDto => new NotificationQueuedDto(
                notificationId: $notification->id,
                recipientId: $notification->subscriber_id,
                status: $notification->status,
            ))
            ->values()
            ->all();

        return new SendNotificationsResultDto(
            batchId: $batch->id,
            status: 'accepted',
            channel: $batch->channel,
            priority: (int) $batch->priority,
            message: $batch->message,
            recipientsCount: (int) $batch->recipients_count,
            notifications: $queuedNotifications,
            requestId: $command->requestId,
            idempotencyKey: $command->idempotencyKey,
            createdAt: CarbonImmutable::parse($batch->created_at)->toJSON(),
        );
    }

    public function listSubscriberNotifications(ListSubscriberNotificationsQuery $query): SubscriberNotificationsPageDto
    {
        $cursor = $query->cursor !== null ? CursorCodec::decode($query->cursor) : null;

        $notifications = DB::table('notifications')
            ->where('subscriber_id', $query->subscriberId)
            ->when($query->status !== null, fn ($builder) => $builder->where('status', $query->status))
            ->when($query->channel !== null, fn ($builder) => $builder->where('channel', $query->channel))
            ->when($cursor !== null, function ($builder) use ($cursor): void {
                $builder->where(function ($builder) use ($cursor): void {
                    $builder
                        ->where('created_at', '<', $cursor['created_at'])
                        ->orWhere(function ($builder) use ($cursor): void {
                            $builder
                                ->where('created_at', '=', $cursor['created_at'])
                                ->where('id', '<', $cursor['id']);
                        });
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($query->limit + 1)
            ->get();

        if ($notifications->isEmpty()) {
            return new SubscriberNotificationsPageDto([], null);
        }

        $hasNextPage = $notifications->count() > $query->limit;
        $pageNotifications = $notifications->take($query->limit)->values();
        $lastNotification = $pageNotifications->last();
        $nextCursor = $hasNextPage && $lastNotification !== null
            ? CursorCodec::encode((string) $lastNotification->created_at, $lastNotification->id)
            : null;

        $historyByNotification = DB::table('notification_status_history')
            ->whereIn('notification_id', $pageNotifications->pluck('id')->all())
            ->orderBy('changed_at')
            ->get()
            ->groupBy('notification_id');

        $subscriberNotifications = $pageNotifications
            ->map(function (object $notification) use ($historyByNotification): SubscriberNotificationDto {
                return $this->mapSubscriberNotification(
                    $notification,
                    $this->historyForNotification($historyByNotification, $notification->id)
                );
            })
            ->values()
            ->all();

        return new SubscriberNotificationsPageDto($subscriberNotifications, $nextCursor);
    }

    private function historyForNotification(Collection $historyByNotification, string $notificationId): Collection
    {
        $history = $historyByNotification->get($notificationId);

        if ($history instanceof Collection) {
            return $history;
        }

        return collect();
    }

    private function mapSubscriberNotification(object $notification, Collection $history): SubscriberNotificationDto
    {
        return new SubscriberNotificationDto(
            notificationId: $notification->id,
            batchId: $notification->batch_id,
            channel: $notification->channel,
            message: $notification->message,
            priority: (int) $notification->priority,
            currentStatus: $notification->status,
            provider: $notification->provider,
            providerStatus: $notification->provider_status,
            providerMessageId: $notification->provider_message_id,
            createdAt: CarbonImmutable::parse($notification->created_at)->toJSON(),
            updatedAt: CarbonImmutable::parse($notification->updated_at)->toJSON(),
            statusHistory: $history
                ->map(fn (object $item): StatusHistoryItemDto => new StatusHistoryItemDto(
                    status: $item->status,
                    provider: $item->provider,
                    providerStatus: $item->provider_status,
                    changedAt: CarbonImmutable::parse($item->changed_at)->toJSON(),
                    reason: $item->reason,
                ))
                ->values()
                ->all(),
        );
    }
}
