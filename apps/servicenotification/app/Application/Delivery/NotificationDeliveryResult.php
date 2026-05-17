<?php

declare(strict_types=1);

namespace App\Application\Delivery;

final readonly class NotificationDeliveryResult
{
    public function __construct(
        public DeliveryAction $action,
        public string $notificationId,
        public int $attempt,
        public ?string $reason = null,
        public ?int $retryAfterSeconds = null,
    ) {}
}
