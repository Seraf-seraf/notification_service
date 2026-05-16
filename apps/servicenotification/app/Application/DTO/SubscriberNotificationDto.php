<?php

declare(strict_types=1);

namespace App\Application\DTO;

use App\Application\Contracts\Arrayable;
use App\Application\Support\ArrayableNormalizer;
use JsonSerializable;

final readonly class SubscriberNotificationDto implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $notificationId,
        public string $batchId,
        public string $channel,
        public string $message,
        public int $priority,
        public string $currentStatus,
        public ?string $provider,
        public ?string $providerStatus,
        public ?string $providerMessageId,
        public string $createdAt,
        public string $updatedAt,
        public array $statusHistory,
    ) {}

    public function toArray(): array
    {
        return [
            'notification_id' => $this->notificationId,
            'batch_id' => $this->batchId,
            'channel' => $this->channel,
            'message' => $this->message,
            'priority' => $this->priority,
            'current_status' => $this->currentStatus,
            'provider' => $this->provider,
            'provider_status' => $this->providerStatus,
            'provider_message_id' => $this->providerMessageId,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'status_history' => ArrayableNormalizer::list($this->statusHistory),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
