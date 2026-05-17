<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Application\Outbox\MessagePublisher;
use App\Application\Outbox\OutboxMessage;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use RuntimeException;

final class RabbitMqMessagePublisher implements MessagePublisher
{
    private ?AMQPStreamConnection $connection = null;

    public function __construct(
        private readonly RabbitMqConnectionFactory $connectionFactory,
        private readonly RabbitMqMessageFactory $messageFactory,
    ) {}

    public function publish(OutboxMessage $message): void
    {
        $channel = $this->connection()->channel();
        $amqpMessage = $this->messageFactory->make($message);
        $returnedMessage = null;

        $channel->confirm_select();
        $channel->set_return_listener(function (
            int $replyCode,
            string $replyText,
            string $exchange,
            string $routingKey,
        ) use (&$returnedMessage): void {
            $returnedMessage = sprintf(
                'RabbitMQ returned unroutable message: code=%d text=%s exchange=%s routing_key=%s',
                $replyCode,
                $replyText,
                $exchange,
                $routingKey,
            );
        });
        $channel->set_nack_handler(static function () use ($message): void {
            throw new RuntimeException('RabbitMQ nack received for outbox message '.$message->outboxMessageId);
        });

        try {
            $channel->basic_publish(
                msg: $amqpMessage,
                exchange: $message->exchange,
                routing_key: $message->routingKey,
                mandatory: true,
            );
            $channel->wait_for_pending_acks_returns((float) config('rabbitmq.publish_confirm_timeout', 5));

            if ($returnedMessage !== null) {
                throw new RuntimeException($returnedMessage);
            }
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
