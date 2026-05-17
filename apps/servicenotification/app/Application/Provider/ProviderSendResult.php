<?php

declare(strict_types=1);

namespace App\Application\Provider;

final readonly class ProviderSendResult
{
    public function __construct(
        public string $providerMessageId,
        public string $providerStatus,
        public bool $deduplicated = false,
    ) {}
}
