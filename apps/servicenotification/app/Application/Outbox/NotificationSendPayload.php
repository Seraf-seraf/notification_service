<?php

declare(strict_types=1);

namespace App\Application\Outbox;

final readonly class NotificationSendPayload
{
    public function __construct(
        public string $notificationId,
        public string $batchId,
        public string $channel,
        public int $priority,
        public int $attempt,
        public string $requestId,
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            notificationId: OutboxField::requiredString($payload, 'notification_id'),
            batchId: OutboxField::requiredString($payload, 'batch_id'),
            channel: OutboxField::requiredString($payload, 'channel'),
            priority: OutboxField::requiredInt($payload, 'priority'),
            attempt: OutboxField::requiredInt($payload, 'attempt'),
            requestId: OutboxField::requiredString($payload, 'request_id'),
        );
    }

    public function toArray(): array
    {
        return [
            'notification_id' => $this->notificationId,
            'batch_id' => $this->batchId,
            'channel' => $this->channel,
            'priority' => $this->priority,
            'attempt' => $this->attempt,
            'request_id' => $this->requestId,
        ];
    }
}
