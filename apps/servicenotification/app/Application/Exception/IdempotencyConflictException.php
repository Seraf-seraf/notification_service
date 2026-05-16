<?php

declare(strict_types=1);

namespace App\Application\Exception;

use RuntimeException;

final class IdempotencyConflictException extends RuntimeException
{
    public function __construct(
        public readonly string $idempotencyKey,
    ) {
        parent::__construct('Idempotency key was already used with another payload.');
    }
}
