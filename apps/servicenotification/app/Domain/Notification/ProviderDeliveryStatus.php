<?php

declare(strict_types=1);

namespace App\Domain\Notification;

enum ProviderDeliveryStatus: string
{
    case Accepted = 'accepted';
    case Delivered = 'delivered';
    case TemporaryFailed = 'temporary_failed';
    case Failed = 'failed';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
