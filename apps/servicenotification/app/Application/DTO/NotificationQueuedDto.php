<?php

declare(strict_types=1);

namespace App\Application\DTO;

use App\Application\Contracts\Arrayable;
use JsonSerializable;

final readonly class NotificationQueuedDto implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $notificationId,
        public string $recipientId,
        public string $status,
    ) {}

    public function toArray(): array
    {
        return [
            'notification_id' => $this->notificationId,
            'recipient_id' => $this->recipientId,
            'status' => $this->status,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
