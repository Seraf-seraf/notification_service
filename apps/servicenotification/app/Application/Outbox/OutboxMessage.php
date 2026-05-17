<?php

declare(strict_types=1);

namespace App\Application\Outbox;

use JsonException;

final readonly class OutboxMessage
{
    public function __construct(
        public string $id,
        public string $outboxMessageId,
        public string $batchId,
        public string $notificationId,
        public string $messageType,
        public string $exchange,
        public string $routingKey,
        public string $channel,
        public int $priority,
        public NotificationSendPayload $payload,
        public OutboxHeaders $headers,
        public int $publishAttempts,
    ) {}

    public static function fromDatabaseRow(object $row): self
    {
        return new self(
            id: (string) $row->id,
            outboxMessageId: (string) $row->outbox_message_id,
            batchId: (string) $row->batch_id,
            notificationId: (string) $row->notification_id,
            messageType: (string) $row->message_type,
            exchange: (string) $row->exchange,
            routingKey: (string) $row->routing_key,
            channel: (string) $row->channel,
            priority: (int) $row->priority,
            payload: NotificationSendPayload::fromArray(self::decodeJsonObject((string) $row->payload)),
            headers: OutboxHeaders::fromArray($row->headers !== null ? self::decodeJsonObject((string) $row->headers) : []),
            publishAttempts: (int) $row->publish_attempts,
        );
    }

    public function encodedPayload(): string
    {
        return json_encode($this->payload->toArray(), JSON_THROW_ON_ERROR);
    }

    private static function decodeJsonObject(string $value): array
    {
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
