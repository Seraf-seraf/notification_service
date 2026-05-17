<?php

declare(strict_types=1);

namespace App\Application\Provider;

interface NotificationProviderRegistry
{
    public function forChannel(string $channel): NotificationProviderClient;
}
