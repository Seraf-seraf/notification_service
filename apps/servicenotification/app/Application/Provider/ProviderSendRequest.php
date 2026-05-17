<?php

declare(strict_types=1);

namespace App\Application\Provider;

final readonly class ProviderSendRequest
{
    public function __construct(
        public string $notificationId,
        public string $subscriberId,
        public string $channel,
        public string $message,
        public int $priority,
        public string $requestId,
    ) {}
}
