<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Outbox\OutboxPublisher;
use Illuminate\Console\Command;

final class PublishOutboxMessagesCommand extends Command
{
    protected $signature = 'notifications:outbox:publish
        {--limit=100 : Maximum outbox messages per run}
        {--daemon : Keep publishing outbox messages in a loop}
        {--sleep=2 : Seconds to sleep between daemon iterations}';

    protected $description = 'Publish available notification outbox messages to RabbitMQ.';

    public function handle(OutboxPublisher $publisher): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $daemon = (bool) $this->option('daemon');
        $sleepSeconds = max(1, (int) $this->option('sleep'));
        $shouldStop = false;
        $hasFailures = false;

        $this->registerSignalHandlers(static function () use (&$shouldStop): void {
            $shouldStop = true;
        });

        do {
            $result = $publisher->publishPending($limit);
            $hasFailures = $hasFailures || $result->failed > 0;

            $this->components->info(sprintf(
                'Outbox selected=%d published=%d failed=%d',
                $result->selected,
                $result->published,
                $result->failed,
            ));

            if (! $daemon || $shouldStop) {
                break;
            }

            $this->sleepInterruptible($sleepSeconds, $shouldStop);
        } while (! $shouldStop);

        return $hasFailures && ! $daemon ? self::FAILURE : self::SUCCESS;
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

    private function sleepInterruptible(int $seconds, bool &$shouldStop): void
    {
        for ($elapsed = 0; $elapsed < $seconds && ! $shouldStop; $elapsed++) {
            sleep(1);
        }
    }
}
