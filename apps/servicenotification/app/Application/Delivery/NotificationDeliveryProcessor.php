<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Application\Outbox\NotificationSendPayload;

final readonly class NotificationDeliveryProcessor
{
    public function __construct(
        private NotificationSendHandler $handler,
        private DeliveryMessagePublisher $publisher,
    ) {}

    public function process(NotificationSendPayload $payload): NotificationDeliveryResult
    {
        $result = $this->handler->handle($payload);

        if ($result->action === DeliveryAction::Retry) {
            $this->publisher->retry($payload, (int) $result->retryAfterSeconds, (string) $result->reason);
        }

        if ($result->action === DeliveryAction::DeadLetter) {
            $this->publisher->deadLetter($payload, (string) $result->reason);
        }

        return $result;
    }
}
