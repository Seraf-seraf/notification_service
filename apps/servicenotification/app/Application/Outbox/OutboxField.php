<?php

declare(strict_types=1);

namespace App\Application\Outbox;

use InvalidArgumentException;

final class OutboxField
{
    public static function requiredString(array $payload, string $field): string
    {
        if (! array_key_exists($field, $payload)) {
            throw new InvalidArgumentException('Outbox field is missing: '.$field);
        }

        $value = $payload[$field];

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException('Outbox field must be a non-empty string: '.$field);
        }

        return $value;
    }

    public static function requiredInt(array $payload, string $field): int
    {
        if (! array_key_exists($field, $payload)) {
            throw new InvalidArgumentException('Outbox field is missing: '.$field);
        }

        $value = $payload[$field];

        if (! is_int($value)) {
            throw new InvalidArgumentException('Outbox field must be an integer: '.$field);
        }

        return $value;
    }

    public static function optionalString(array $payload, string $field): ?string
    {
        if (! array_key_exists($field, $payload) || $payload[$field] === null) {
            return null;
        }

        $value = $payload[$field];

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException('Outbox field must be a non-empty string: '.$field);
        }

        return $value;
    }
}
