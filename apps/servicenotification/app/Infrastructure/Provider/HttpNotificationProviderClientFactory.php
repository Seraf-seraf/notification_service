<?php

declare(strict_types=1);

namespace App\Infrastructure\Provider;

final class HttpNotificationProviderClientFactory
{
    public function make(string $channel): HttpNotificationProviderClient
    {
        $config = config('notification.providers.'.$channel, []);

        return new HttpNotificationProviderClient(
            channel: $channel,
            baseUrl: rtrim((string) ($config['base_url'] ?? ''), '/'),
            timeoutSeconds: (float) ($config['timeout_seconds'] ?? 3),
            webhookUrl: (string) config('notification.provider_webhook_url', ''),
        );
    }
}
