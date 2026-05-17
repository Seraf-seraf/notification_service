<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Application\Outbox\OutboxMessage;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

final class RabbitMqMessageFactory
{
    public function make(OutboxMessage $message): AMQPMessage
    {
        $headers = $message->headers->toArray();
        $requestId = $message->headers->requestId ?? $message->payload->requestId;

        return new AMQPMessage($message->encodedPayload(), [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'priority' => $message->priority,
            'message_id' => $message->outboxMessageId,
            'correlation_id' => $requestId,
            'type' => $message->messageType,
            'application_headers' => new AMQPTable($headers),
        ]);
    }
}
