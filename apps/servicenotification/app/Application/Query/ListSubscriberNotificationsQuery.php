<?php

declare(strict_types=1);

namespace App\Application\Query;

final readonly class ListSubscriberNotificationsQuery
{
    public function __construct(
        public string $subscriberId,
        public string $requestId,
        public ?string $status = null,
        public ?string $channel = null,
        public int $limit = 50,
        public ?string $cursor = null,
    ) {}
}
