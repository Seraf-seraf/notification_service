<?php

declare(strict_types=1);

namespace App\Application\DTO;

use App\Application\Contracts\Arrayable;
use JsonSerializable;

final readonly class StatusHistoryItemDto implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $status,
        public ?string $provider,
        public ?string $providerStatus,
        public string $changedAt,
        public ?string $reason,
    ) {}

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'provider' => $this->provider,
            'provider_status' => $this->providerStatus,
            'changed_at' => $this->changedAt,
            'reason' => $this->reason,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
