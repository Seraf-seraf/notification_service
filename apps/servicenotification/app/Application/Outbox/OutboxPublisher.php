<?php

declare(strict_types=1);

namespace App\Application\Outbox;

use App\Domain\Outbox\OutboxMessageStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class OutboxPublisher
{
    public function __construct(
        private MessagePublisher $publisher,
    ) {}

    public function publishPending(int $limit = 100): OutboxPublishResult
    {
        return DB::transaction(function () use ($limit): OutboxPublishResult {
            $now = CarbonImmutable::now('UTC');
            $rows = $this->selectAvailableRows($limit, $now);
            $published = 0;
            $failed = 0;

            foreach ($rows as $row) {
                $message = OutboxMessage::fromDatabaseRow($row);
                $attempt = $message->publishAttempts + 1;

                try {
                    $this->publisher->publish($message);
                    $this->markPublished($message->id, $attempt, $now);
                    $published++;
                } catch (Throwable $exception) {
                    $this->markFailed($message->id, $attempt, $now, $exception);
                    $failed++;
                }
            }

            return new OutboxPublishResult(
                selected: count($rows),
                published: $published,
                failed: $failed,
            );
        });
    }

    private function selectAvailableRows(int $limit, CarbonImmutable $now): array
    {
        return DB::table('outbox_messages')
            ->whereIn('status', [
                OutboxMessageStatus::Pending->value,
                OutboxMessageStatus::Failed->value,
            ])
            ->where('available_at', '<=', $now)
            ->orderByDesc('priority')
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->lockForUpdate()
            ->get()
            ->all();
    }

    private function markPublished(string $messageId, int $attempt, CarbonImmutable $now): void
    {
        DB::table('outbox_messages')
            ->where('id', $messageId)
            ->whereIn('status', [
                OutboxMessageStatus::Pending->value,
                OutboxMessageStatus::Failed->value,
            ])
            ->update([
                'status' => OutboxMessageStatus::Published->value,
                'publish_attempts' => $attempt,
                'last_error' => null,
                'published_at' => $now,
                'updated_at' => $now,
            ]);
    }

    private function markFailed(string $messageId, int $attempt, CarbonImmutable $now, Throwable $exception): void
    {
        DB::table('outbox_messages')
            ->where('id', $messageId)
            ->whereIn('status', [
                OutboxMessageStatus::Pending->value,
                OutboxMessageStatus::Failed->value,
            ])
            ->update([
                'status' => OutboxMessageStatus::Failed->value,
                'publish_attempts' => $attempt,
                'last_error' => mb_substr($exception->getMessage(), 0, 1000),
                'available_at' => $now->addSeconds($this->backoffSeconds($attempt)),
                'updated_at' => $now,
            ]);
    }

    private function backoffSeconds(int $attempt): int
    {
        $backoff = config('notification.retry_backoff_seconds', [30, 120, 300, 900, 1800]);
        $index = max(0, $attempt - 1);

        return (int) ($backoff[$index] ?? end($backoff) ?: 30);
    }
}
