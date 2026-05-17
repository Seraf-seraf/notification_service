<?php

declare(strict_types=1);

namespace App\Application\Outbox;

final readonly class OutboxPublishResult
{
    public function __construct(
        public int $selected,
        public int $published,
        public int $failed,
    ) {}
}
