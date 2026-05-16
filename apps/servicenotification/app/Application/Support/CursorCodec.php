<?php

declare(strict_types=1);

namespace App\Application\Support;

use JsonException;

final class CursorCodec
{
    public static function encode(string $createdAt, string $id): string
    {
        $json = json_encode([
            'created_at' => $createdAt,
            'id' => $id,
        ], JSON_THROW_ON_ERROR);

        return bin2hex($json);
    }

    public static function decode(string $cursor): ?array
    {
        if ($cursor === '' || strlen($cursor) % 2 !== 0 || ! ctype_xdigit($cursor)) {
            return null;
        }

        $json = hex2bin($cursor);
        if ($json === false) {
            return null;
        }

        try {
            $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($payload)) {
            return null;
        }

        if (! is_string($payload['created_at'] ?? null) || ! is_string($payload['id'] ?? null)) {
            return null;
        }

        return $payload;
    }
}
