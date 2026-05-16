<?php

declare(strict_types=1);

namespace App\Application\DTO;

use App\Application\Contracts\Arrayable;
use App\Application\Support\ArrayableNormalizer;
use JsonSerializable;

final readonly class SendNotificationsResultDto implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $batchId,
        public string $status,
        public string $channel,
        public int $priority,
        public string $message,
        public int $recipientsCount,
        public array $notifications,
        public string $requestId,
        public ?string $idempotencyKey,
        public string $createdAt,
    ) {}

    public function toArray(): array
    {
        return [
            'data' => [
                'batch_id' => $this->batchId,
                'status' => $this->status,
                'channel' => $this->channel,
                'priority' => $this->priority,
                'message' => $this->message,
                'recipients_count' => $this->recipientsCount,
                'notifications' => ArrayableNormalizer::list($this->notifications),
            ],
            'meta' => [
                'request_id' => $this->requestId,
                'idempotency_key' => $this->idempotencyKey,
                'created_at' => $this->createdAt,
            ],
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
