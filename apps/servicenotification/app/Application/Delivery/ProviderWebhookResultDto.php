<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Application\Contracts\Arrayable;

final readonly class ProviderWebhookResultDto implements Arrayable
{
    public function __construct(
        public string $providerMessageId,
        public string $messageId,
        public string $providerStatus,
        public string $notificationStatus,
        public bool $applied,
        public string $requestId,
    ) {}

    public function toArray(): array
    {
        return [
            'data' => [
                'provider_message_id' => $this->providerMessageId,
                'message_id' => $this->messageId,
                'provider_status' => $this->providerStatus,
                'notification_status' => $this->notificationStatus,
                'applied' => $this->applied,
            ],
            'meta' => [
                'request_id' => $this->requestId,
            ],
        ];
    }
}
