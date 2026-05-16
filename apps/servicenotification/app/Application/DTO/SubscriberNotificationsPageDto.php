<?php

declare(strict_types=1);

namespace App\Application\DTO;

use App\Application\Contracts\Arrayable;
use App\Application\Support\ArrayableNormalizer;
use JsonSerializable;

final readonly class SubscriberNotificationsPageDto implements Arrayable, JsonSerializable
{
    public function __construct(
        public array $notifications,
        public ?string $nextCursor,
    ) {}

    public function toArray(): array
    {
        return [
            'notifications' => ArrayableNormalizer::list($this->notifications),
            'next_cursor' => $this->nextCursor,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
