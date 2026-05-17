<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Application\Delivery\DeliveryMessagePublisher;
use App\Application\Outbox\NotificationSendPayload;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

final class RabbitMqDeliveryMessagePublisher implements DeliveryMessagePublisher
{
    private ?AMQPStreamConnection $connection = null;

    public function __construct(
        private readonly RabbitMqConnectionFactory $connectionFactory,
    ) {}

    public function retry(NotificationSendPayload $payload, int $retryAfterSeconds, string $reason): void
    {
        $nextPayload = new NotificationSendPayload(
            notificationId: $payload->notificationId,
            batchId: $payload->batchId,
            channel: $payload->channel,
            priority: $payload->priority,
            attempt: $payload->attempt + 1,
            requestId: $payload->requestId,
        );

        $this->publish(
            exchange: (string) config('rabbitmq.retry_exchange', 'notifications.retry.exchange'),
            routingKey: sprintf('notifications.%s.retry.%d', $payload->channel, $retryAfterSeconds),
            payload: $nextPayload,
            reason: $reason,
        );
    }

    public function deadLetter(NotificationSendPayload $payload, string $reason): void
    {
        $this->publish(
            exchange: (string) config('rabbitmq.dead_letter_exchange', 'notifications.dlx'),
            routingKey: (string) config('rabbitmq.routing_keys.dead_letter', 'notifications.dlq'),
            payload: $payload,
            reason: $reason,
        );
    }

    private function publish(string $exchange, string $routingKey, NotificationSendPayload $payload, string $reason): void
    {
        $channel = $this->connection()->channel();

        try {
            $channel->basic_publish(
                msg: new AMQPMessage(json_encode($payload->toArray(), JSON_THROW_ON_ERROR), [
                    'content_type' => 'application/json',
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    'priority' => $payload->priority,
                    'message_id' => $payload->notificationId.'-'.$payload->attempt,
                    'correlation_id' => $payload->requestId,
                    'type' => 'notification.send',
                    'application_headers' => new AMQPTable([
                        'X-Request-Id' => $payload->requestId,
                        'x-reason' => $reason,
                    ]),
                ]),
                exchange: $exchange,
                routing_key: $routingKey,
            );
        } finally {
            $channel->close();
        }
    }

    private function connection(): AMQPStreamConnection
    {
        if ($this->connection === null || ! $this->connection->isConnected()) {
            $this->connection = $this->connectionFactory->make();
        }

        return $this->connection;
    }

    public function __destruct()
    {
        if ($this->connection !== null && $this->connection->isConnected()) {
            $this->connection->close();
        }
    }
}
