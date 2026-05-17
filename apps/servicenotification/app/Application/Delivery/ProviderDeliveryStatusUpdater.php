<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Application\Command\UpdateProviderDeliveryStatusCommand;
use App\Domain\Notification\NotificationProvider;
use App\Domain\Notification\NotificationStatus;
use App\Observability\MetricsRegistry;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class ProviderDeliveryStatusUpdater
{
    public function __construct(
        private ProviderStatusMapper $mapper,
        private MetricsRegistry $metrics,
    ) {}

    public function update(UpdateProviderDeliveryStatusCommand $command): ProviderWebhookResultDto
    {
        $provider = NotificationProvider::fromChannel($command->providerChannel)->value;
        $mappedNotificationStatus = $this->mapper->notificationStatus($command->providerStatus);
        $mappedProviderStatus = $this->mapper->providerStatus($command->providerStatus);

        return DB::transaction(function () use (
            $command,
            $provider,
            $mappedNotificationStatus,
            $mappedProviderStatus,
        ): ProviderWebhookResultDto {
            $notification = DB::table('notifications')
                ->where('id', $command->messageId)
                ->where('provider', $provider)
                ->lockForUpdate()
                ->first();

            if (! is_object($notification)) {
                throw new NotFoundHttpException('Notification message was not found.');
            }

            $applied = $this->canApply((string) $notification->status, $mappedNotificationStatus);

            if ($applied) {
                DB::table('notifications')
                    ->where('id', $command->messageId)
                    ->update([
                        'status' => $mappedNotificationStatus,
                        'provider_status' => $mappedProviderStatus,
                        'provider_message_id' => $notification->provider_message_id ?: $command->providerMessageId,
                        'updated_at' => $command->occurredAt,
                    ]);

                $now = now('UTC');

                DB::table('notification_status_history')->insert([
                    'notification_id' => $command->messageId,
                    'status' => $mappedNotificationStatus,
                    'provider' => $provider,
                    'provider_status' => $mappedProviderStatus,
                    'reason' => $command->reason !== null ? mb_substr($command->reason, 0, 512) : 'Provider status: '.$command->providerStatus,
                    'changed_at' => $command->occurredAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $this->metrics->recordNotificationStatus(
                    channel: (string) $notification->channel,
                    status: $mappedNotificationStatus,
                );
            }

            return new ProviderWebhookResultDto(
                providerMessageId: $command->providerMessageId,
                messageId: $command->messageId,
                providerStatus: $command->providerStatus,
                notificationStatus: $applied ? $mappedNotificationStatus : (string) $notification->status,
                applied: $applied,
                requestId: $command->requestId,
            );
        });
    }

    private function canApply(string $currentStatus, string $nextStatus): bool
    {
        if ($currentStatus === $nextStatus) {
            return false;
        }

        if (in_array($currentStatus, [
            NotificationStatus::Delivered->value,
            NotificationStatus::Dropped->value,
        ], true)) {
            return false;
        }

        return $this->rank($nextStatus) >= $this->rank($currentStatus);
    }

    private function rank(string $status): int
    {
        return match ($status) {
            NotificationStatus::Queued->value => 0,
            NotificationStatus::Sent->value => 1,
            NotificationStatus::Delivered->value, NotificationStatus::Dropped->value => 2,
            default => -1,
        };
    }
}
