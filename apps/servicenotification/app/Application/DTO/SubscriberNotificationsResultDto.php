<?php

declare(strict_types=1);

namespace App\Application\DTO;

use App\Application\Contracts\Arrayable;
use App\Application\Support\ArrayableNormalizer;
use JsonSerializable;

final readonly class SubscriberNotificationsResultDto implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $subscriberId,
        public array $notifications,
        public string $requestId,
        public int $limit,
        public ?string $nextCursor,
    ) {}

    public function toArray(): array
    {
        return [
            'data' => [
                'subscriber_id' => $this->subscriberId,
                'notifications' => ArrayableNormalizer::list($this->notifications),
            ],
            'meta' => [
                'request_id' => $this->requestId,
                'limit' => $this->limit,
                'next_cursor' => $this->nextCursor,
            ],
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
