<?php

declare(strict_types=1);

namespace App\Infrastructure\Provider;

use App\Application\Provider\NotificationProviderClient;
use App\Application\Provider\PermanentProviderException;
use App\Application\Provider\ProviderSendRequest;
use App\Application\Provider\ProviderSendResult;
use App\Application\Provider\TemporaryProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;

final readonly class HttpNotificationProviderClient implements NotificationProviderClient
{
    public function __construct(
        private string $channel,
        private string $baseUrl,
        private float $timeoutSeconds,
        private string $webhookUrl,
    ) {}

    public function send(ProviderSendRequest $request): ProviderSendResult
    {
        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withHeaders([
                    'X-Request-Id' => $request->requestId,
                    'Idempotency-Key' => $request->notificationId,
                ])
                ->post($this->baseUrl.'/api/v1/messages', [
                    'message_id' => $request->notificationId,
                    'recipient_id' => $request->subscriberId,
                    'channel' => $request->channel,
                    'text' => $request->message,
                    'priority' => $request->priority,
                    'webhook_url' => $this->webhookUrl !== ''
                        ? $this->webhookUrl
                        : url('/api/providers/'.$this->channel.'/webhooks'),
                    'metadata' => [
                        'request_id' => $request->requestId,
                    ],
                ]);
        } catch (ConnectionException $exception) {
            throw new TemporaryProviderException($exception->getMessage(), previous: $exception);
        }

        if ($response->status() === 409 || $response->status() === 422 || $response->status() === 400) {
            throw new PermanentProviderException($this->errorMessage($response->body()));
        }

        if ($response->serverError() || $response->status() === 429) {
            throw new TemporaryProviderException($this->errorMessage($response->body()));
        }

        if (! $response->successful()) {
            throw new TemporaryProviderException('Provider returned HTTP '.$response->status());
        }

        $data = $response->json();

        if (! is_array($data) || ! isset($data['provider_message_id'], $data['status'])) {
            throw new TemporaryProviderException('Provider returned malformed response.');
        }

        return new ProviderSendResult(
            providerMessageId: (string) $data['provider_message_id'],
            providerStatus: (string) $data['status'],
            deduplicated: (bool) ($data['deduplicated'] ?? false),
        );
    }

    private function errorMessage(string $body): string
    {
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return mb_substr($body !== '' ? $body : 'Provider request failed.', 0, 512);
        }

        if (is_array($data)) {
            return (string) ($data['message'] ?? $data['error'] ?? 'Provider request failed.');
        }

        return 'Provider request failed.';
    }
}
