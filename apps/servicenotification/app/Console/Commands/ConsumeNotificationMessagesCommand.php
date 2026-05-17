<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Delivery\NotificationDeliveryProcessor;
use App\Application\Outbox\NotificationSendPayload;
use App\Infrastructure\Messaging\RabbitMqConnectionFactory;
use Illuminate\Console\Command;
use JsonException;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

final class ConsumeNotificationMessagesCommand extends Command
{
    protected $signature = 'notifications:worker:send
        {channel=all : sms, email or all}
        {--once : Stop after one consumed message}';

    protected $description = 'Consume notification send messages from RabbitMQ with manual ack and retry/DLQ handling.';

    public function handle(
        RabbitMqConnectionFactory $connectionFactory,
        NotificationDeliveryProcessor $processor,
    ): int {
        $requestedChannel = (string) $this->argument('channel');
        $channels = $requestedChannel === 'all' ? ['sms', 'email'] : [$requestedChannel];
        $connection = $connectionFactory->make();
        $amqpChannel = $connection->channel();
        $processed = 0;

        $amqpChannel->basic_qos(
            prefetch_size: 0,
            prefetch_count: (int) config('rabbitmq.prefetch', 10),
            a_global: false,
        );

        $this->registerSignalHandlers(static function () use ($amqpChannel): void {
            $amqpChannel->stopConsume();
        });

        foreach ($channels as $channel) {
            if (! in_array($channel, ['sms', 'email'], true)) {
                $this->components->error('Unsupported channel: '.$channel);

                return self::FAILURE;
            }

            $amqpChannel->basic_consume(
                queue: (string) config('rabbitmq.queues.'.$channel),
                callback: function (AMQPMessage $message) use ($processor, &$processed): void {
                    $payload = $this->payload($message);
                    $processor->process($payload);

                    $message->ack();
                    $processed++;

                    if ($this->option('once')) {
                        $message->getChannel()?->stopConsume();
                    }
                },
            );
        }

        try {
            while ($amqpChannel->is_consuming()) {
                $amqpChannel->wait();
            }
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $amqpChannel->close();
            $connection->close();
        }

        $this->components->info('Processed notification messages: '.$processed);

        return self::SUCCESS;
    }

    private function payload(AMQPMessage $message): NotificationSendPayload
    {
        try {
            $decoded = json_decode($message->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new JsonException('Invalid notification message payload: '.$exception->getMessage(), previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new JsonException('Invalid notification message payload.');
        }

        return NotificationSendPayload::fromArray($decoded);
    }

    private function registerSignalHandlers(callable $stop): void
    {
        if (! function_exists('pcntl_async_signals') || ! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, static function () use ($stop): void {
                $stop();
            });
        }
    }
}
