<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Outbox\OutboxPublisher;
use Illuminate\Console\Command;

final class PublishOutboxMessagesCommand extends Command
{
    protected $signature = 'notifications:outbox:publish {--limit=100 : Maximum outbox messages per run}';

    protected $description = 'Publish available notification outbox messages to RabbitMQ.';

    public function handle(OutboxPublisher $publisher): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $result = $publisher->publishPending($limit);

        $this->components->info(sprintf(
            'Outbox selected=%d published=%d failed=%d',
            $result->selected,
            $result->published,
            $result->failed,
        ));

        return $result->failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
