<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Application\Outbox\NotificationSendPayload;

interface DeliveryMessagePublisher
{
    public function retry(NotificationSendPayload $payload, int $retryAfterSeconds, string $reason): void;

    public function deadLetter(NotificationSendPayload $payload, string $reason): void;
}
