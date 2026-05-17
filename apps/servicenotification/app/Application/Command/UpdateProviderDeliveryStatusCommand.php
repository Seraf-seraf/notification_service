<?php

declare(strict_types=1);

namespace App\Application\Command;

use Carbon\CarbonImmutable;

final readonly class UpdateProviderDeliveryStatusCommand
{
    public function __construct(
        public string $providerChannel,
        public string $messageId,
        public string $providerMessageId,
        public string $providerStatus,
        public ?string $reason,
        public CarbonImmutable $occurredAt,
        public string $requestId,
    ) {}
}
