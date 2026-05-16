<?php

declare(strict_types=1);

namespace App\Domain\Notification;

enum NotificationProvider: string
{
    case SmsMock = 'sms_mock';
    case EmailMock = 'email_mock';

    public static function fromChannel(string $channel): self
    {
        return match ($channel) {
            'sms' => self::SmsMock,
            'email' => self::EmailMock,
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
