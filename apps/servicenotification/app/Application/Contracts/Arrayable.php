<?php

declare(strict_types=1);

namespace App\Application\Contracts;

interface Arrayable
{
    public function toArray(): array;
}
