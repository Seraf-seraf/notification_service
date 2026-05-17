<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Domain\Notification\NotificationStatus;
use App\Domain\Notification\ProviderDeliveryStatus;

final class ProviderStatusMapper
{
    public function notificationStatus(string $providerStatus): string
    {
        return match ($providerStatus) {
            'accepted', 'processing' => NotificationStatus::Sent->value,
            'delivered' => NotificationStatus::Delivered->value,
            default => NotificationStatus::Dropped->value,
        };
    }

    public function providerStatus(string $providerStatus): string
    {
        return match ($providerStatus) {
            'accepted', 'processing' => ProviderDeliveryStatus::Accepted->value,
            'delivered' => ProviderDeliveryStatus::Delivered->value,
            'temporary_failed' => ProviderDeliveryStatus::TemporaryFailed->value,
            default => ProviderDeliveryStatus::Failed->value,
        };
    }
}
