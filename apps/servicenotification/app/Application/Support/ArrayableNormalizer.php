<?php

declare(strict_types=1);

namespace App\Application\Support;

use App\Application\Contracts\Arrayable;
use InvalidArgumentException;

final class ArrayableNormalizer
{
    public static function list(iterable $items): array
    {
        $result = [];

        foreach ($items as $item) {
            if (! $item instanceof Arrayable) {
                throw new InvalidArgumentException('ArrayableNormalizer accepts only Arrayable items.');
            }

            $result[] = $item->toArray();
        }

        return $result;
    }
}
