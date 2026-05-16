<?php

declare(strict_types=1);

namespace App\Application\Command;

final readonly class SendNotificationsCommand
{
    public function __construct(
        public string $channel,
        public string $message,
        public int $priority,
        public array $recipientIds,
        public string $requestId,
        public ?string $idempotencyKey,
    ) {}
}
