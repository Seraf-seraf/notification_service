<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Application\Command\UpdateProviderDeliveryStatusCommand;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

final class ProviderWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provider_message_id' => ['required', 'string', 'min:1', 'max:255'],
            'message_id' => ['required', 'uuid'],
            'status' => ['required', 'string', 'in:accepted,processing,delivered,temporary_failed,permanent_failed,invalid_recipient,expired'],
            'reason' => ['nullable', 'string', 'max:512'],
            'occurred_at' => ['required', 'date'],
            'metadata' => ['sometimes', 'array'],
        ];
    }

    public function toCommand(string $provider): UpdateProviderDeliveryStatusCommand
    {
        $validated = $this->validated();
        $reason = $validated['reason'] ?? null;

        return new UpdateProviderDeliveryStatusCommand(
            providerChannel: $provider,
            messageId: (string) $validated['message_id'],
            providerMessageId: (string) $validated['provider_message_id'],
            providerStatus: (string) $validated['status'],
            reason: is_string($reason) ? $reason : null,
            occurredAt: CarbonImmutable::parse((string) $validated['occurred_at'])->utc(),
            requestId: (string) $this->attributes->get('request_id'),
        );
    }
}
