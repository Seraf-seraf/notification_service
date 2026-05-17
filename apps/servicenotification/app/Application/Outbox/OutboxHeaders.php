<?php

declare(strict_types=1);

namespace App\Application\Outbox;

final readonly class OutboxHeaders
{
    public function __construct(
        public ?string $requestId,
        public ?string $idempotencyKey,
    ) {}

    public static function fromArray(array $headers): self
    {
        return new self(
            requestId: OutboxField::optionalString($headers, 'X-Request-Id'),
            idempotencyKey: OutboxField::optionalString($headers, 'Idempotency-Key'),
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'X-Request-Id' => $this->requestId,
            'Idempotency-Key' => $this->idempotencyKey,
        ], static fn (?string $value): bool => $value !== null);
    }
}
