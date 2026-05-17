<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Application\Outbox\NotificationSendPayload;
use App\Observability\MetricsRegistry;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class NotificationDeliveryProcessor
{
    public function __construct(
        private NotificationSendHandler $handler,
        private DeliveryMessagePublisher $publisher,
        private MetricsRegistry $metrics,
    ) {}

    public function process(NotificationSendPayload $payload): NotificationDeliveryResult
    {
        Log::withContext([
            'request_id' => $payload->requestId,
            'notification_id' => $payload->notificationId,
            'batch_id' => $payload->batchId,
            'channel' => $payload->channel,
            'priority' => $payload->priority,
        ]);

        try {
            $result = $this->handler->handle($payload);
        } catch (Throwable $exception) {
            $this->metrics->recordWorkerFailure($payload->channel, $exception::class);

            throw $exception;
        }

        if ($result->action === DeliveryAction::Retry) {
            $this->publisher->retry($payload, (int) $result->retryAfterSeconds, (string) $result->reason);
        }

        if ($result->action === DeliveryAction::DeadLetter) {
            $this->publisher->deadLetter($payload, (string) $result->reason);
        }

        $this->metrics->recordWorkerResult($payload->channel, $result->action->value);

        return $result;
    }
}
