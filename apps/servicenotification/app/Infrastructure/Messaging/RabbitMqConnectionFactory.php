<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use PhpAmqpLib\Connection\AMQPStreamConnection;

final class RabbitMqConnectionFactory
{
    public function make(): AMQPStreamConnection
    {
        return new AMQPStreamConnection(
            host: (string) config('rabbitmq.host', 'rabbitmq'),
            port: (int) config('rabbitmq.port', 5672),
            user: (string) config('rabbitmq.user', 'notification'),
            password: (string) config('rabbitmq.password', 'notification'),
            vhost: (string) config('rabbitmq.vhost', 'notification'),
            connection_timeout: (float) config('rabbitmq.connection_timeout', 3.0),
            read_write_timeout: (float) config('rabbitmq.read_write_timeout', 3.0),
            heartbeat: (int) config('rabbitmq.heartbeat', 30),
        );
    }
}
