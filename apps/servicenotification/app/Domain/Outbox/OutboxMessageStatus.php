<?php

declare(strict_types=1);

namespace App\Domain\Outbox;

enum OutboxMessageStatus: string
{
    case Pending = 'pending';
    case Published = 'published';
    case Failed = 'failed';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
