<?php

declare(strict_types=1);

namespace App\Infrastructure\Provider;

use App\Application\Provider\NotificationProviderClient;
use App\Application\Provider\NotificationProviderRegistry;
use InvalidArgumentException;

final readonly class ConfiguredNotificationProviderRegistry implements NotificationProviderRegistry
{
    public function __construct(
        private HttpNotificationProviderClientFactory $factory,
    ) {}

    public function forChannel(string $channel): NotificationProviderClient
    {
        if (! in_array($channel, ['sms', 'email'], true)) {
            throw new InvalidArgumentException('Unsupported notification channel: '.$channel);
        }

        return $this->factory->make($channel);
    }
}
