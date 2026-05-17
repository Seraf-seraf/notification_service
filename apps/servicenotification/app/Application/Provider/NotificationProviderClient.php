<?php

declare(strict_types=1);

namespace App\Application\Provider;

interface NotificationProviderClient
{
    public function send(ProviderSendRequest $request): ProviderSendResult;
}
