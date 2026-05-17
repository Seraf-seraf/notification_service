<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Application\Outbox\NotificationSendPayload;
use App\Application\Provider\NotificationProviderRegistry;
use App\Application\Provider\PermanentProviderException;
use App\Application\Provider\ProviderSendRequest;
use App\Application\Provider\TemporaryProviderException;
use App\Domain\Notification\NotificationStatus;
use App\Domain\Notification\ProviderDeliveryStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final readonly class NotificationSendHandler
{
    public function __construct(
        private NotificationProviderRegistry $providers,
    ) {}

    public function handle(NotificationSendPayload $payload): NotificationDeliveryResult
    {
        $lock = Cache::lock('notification-send:'.$payload->notificationId, 30);

        if (! $lock->get()) {
            return new NotificationDeliveryResult(
                action: DeliveryAction::Retry,
                notificationId: $payload->notificationId,
                attempt: max(1, $payload->attempt),
                reason: 'Notification is already being processed.',
                retryAfterSeconds: 1,
            );
        }

        try {
            return $this->handleLocked($payload);
        } finally {
            $lock->release();
        }
    }

    private function handleLocked(NotificationSendPayload $payload): NotificationDeliveryResult
    {
        $attempt = max(1, $payload->attempt);
        $notification = $this->lockNotification($payload->notificationId);

        if ($notification === null) {
            return new NotificationDeliveryResult(
                action: DeliveryAction::Ack,
                notificationId: $payload->notificationId,
                attempt: $attempt,
                reason: 'Notification not found.',
            );
        }

        if ($notification->status !== NotificationStatus::Queued->value) {
            return new NotificationDeliveryResult(
                action: DeliveryAction::Ack,
                notificationId: $payload->notificationId,
                attempt: $attempt,
                reason: 'Notification was already processed.',
            );
        }

        try {
            $result = $this->providers
                ->forChannel((string) $notification->channel)
                ->send(new ProviderSendRequest(
                    notificationId: (string) $notification->id,
                    subscriberId: (string) $notification->subscriber_id,
                    channel: (string) $notification->channel,
                    message: (string) $notification->message,
                    priority: (int) $notification->priority,
                    requestId: $payload->requestId,
                ));

            $this->markSent(
                notificationId: (string) $notification->id,
                provider: (string) $notification->provider,
                providerMessageId: $result->providerMessageId,
                reason: $result->deduplicated
                    ? 'Provider accepted duplicated idempotent send request.'
                    : 'Provider accepted message.',
            );

            return new NotificationDeliveryResult(
                action: DeliveryAction::Ack,
                notificationId: (string) $notification->id,
                attempt: $attempt,
                reason: 'Message sent to provider.',
            );
        } catch (PermanentProviderException $exception) {
            $this->markDropped(
                notificationId: (string) $notification->id,
                provider: (string) $notification->provider,
                providerStatus: ProviderDeliveryStatus::Failed->value,
                reason: $exception->getMessage(),
            );

            return new NotificationDeliveryResult(
                action: DeliveryAction::Ack,
                notificationId: (string) $notification->id,
                attempt: $attempt,
                reason: $exception->getMessage(),
            );
        } catch (TemporaryProviderException $exception) {
            return $this->handleTemporaryFailure(
                notificationId: (string) $notification->id,
                provider: (string) $notification->provider,
                attempt: $attempt,
                reason: $exception->getMessage(),
            );
        }
    }

    private function lockNotification(string $notificationId): ?object
    {
        return DB::transaction(function () use ($notificationId): ?object {
            $notification = DB::table('notifications')
                ->where('id', $notificationId)
                ->lockForUpdate()
                ->first();

            return is_object($notification) ? $notification : null;
        });
    }

    private function markSent(string $notificationId, string $provider, string $providerMessageId, string $reason): void
    {
        DB::transaction(function () use ($notificationId, $provider, $providerMessageId, $reason): void {
            $now = CarbonImmutable::now('UTC');
            $updated = DB::table('notifications')
                ->where('id', $notificationId)
                ->where('status', NotificationStatus::Queued->value)
                ->update([
                    'status' => NotificationStatus::Sent->value,
                    'provider_status' => ProviderDeliveryStatus::Accepted->value,
                    'provider_message_id' => $providerMessageId,
                    'updated_at' => $now,
                ]);

            if ($updated === 0) {
                return;
            }

            $this->insertHistory(
                notificationId: $notificationId,
                status: NotificationStatus::Sent->value,
                provider: $provider,
                providerStatus: ProviderDeliveryStatus::Accepted->value,
                reason: $reason,
                changedAt: $now,
            );
        });
    }

    private function handleTemporaryFailure(string $notificationId, string $provider, int $attempt, string $reason): NotificationDeliveryResult
    {
        $maxAttempts = max(1, (int) config('notification.max_attempts', 5));

        if ($attempt >= $maxAttempts) {
            $this->markDropped(
                notificationId: $notificationId,
                provider: $provider,
                providerStatus: ProviderDeliveryStatus::TemporaryFailed->value,
                reason: 'Retry limit exceeded: '.$reason,
            );

            return new NotificationDeliveryResult(
                action: DeliveryAction::DeadLetter,
                notificationId: $notificationId,
                attempt: $attempt,
                reason: $reason,
            );
        }

        $this->recordRetry(
            notificationId: $notificationId,
            provider: $provider,
            reason: $reason,
        );

        return new NotificationDeliveryResult(
            action: DeliveryAction::Retry,
            notificationId: $notificationId,
            attempt: $attempt,
            reason: $reason,
            retryAfterSeconds: $this->backoffSeconds($attempt),
        );
    }

    private function markDropped(string $notificationId, string $provider, string $providerStatus, string $reason): void
    {
        DB::transaction(function () use ($notificationId, $provider, $providerStatus, $reason): void {
            $now = CarbonImmutable::now('UTC');
            $updated = DB::table('notifications')
                ->where('id', $notificationId)
                ->whereNotIn('status', [
                    NotificationStatus::Delivered->value,
                    NotificationStatus::Dropped->value,
                ])
                ->update([
                    'status' => NotificationStatus::Dropped->value,
                    'provider_status' => $providerStatus,
                    'updated_at' => $now,
                ]);

            if ($updated === 0) {
                return;
            }

            $this->insertHistory(
                notificationId: $notificationId,
                status: NotificationStatus::Dropped->value,
                provider: $provider,
                providerStatus: $providerStatus,
                reason: $reason,
                changedAt: $now,
            );
        });
    }

    private function recordRetry(string $notificationId, string $provider, string $reason): void
    {
        $now = CarbonImmutable::now('UTC');

        DB::table('notification_status_history')->insert([
            'notification_id' => $notificationId,
            'status' => NotificationStatus::Queued->value,
            'provider' => $provider,
            'provider_status' => ProviderDeliveryStatus::TemporaryFailed->value,
            'reason' => 'Temporary provider failure: '.$reason,
            'changed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Log::warning('notification delivery scheduled for retry', [
            'notification_id' => $notificationId,
            'provider' => $provider,
            'reason' => $reason,
        ]);
    }

    private function insertHistory(
        string $notificationId,
        string $status,
        string $provider,
        string $providerStatus,
        string $reason,
        CarbonImmutable $changedAt,
    ): void {
        DB::table('notification_status_history')->insert([
            'notification_id' => $notificationId,
            'status' => $status,
            'provider' => $provider,
            'provider_status' => $providerStatus,
            'reason' => mb_substr($reason, 0, 512),
            'changed_at' => $changedAt,
            'created_at' => $changedAt,
            'updated_at' => $changedAt,
        ]);
    }

    private function backoffSeconds(int $attempt): int
    {
        $backoff = config('notification.retry_backoff_seconds', [30, 120, 300, 900, 1800]);
        $index = max(0, $attempt - 1);

        return (int) ($backoff[$index] ?? end($backoff) ?: 30);
    }
}
