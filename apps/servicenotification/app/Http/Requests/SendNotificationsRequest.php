<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Application\Command\SendNotificationsCommand;
use Illuminate\Foundation\Http\FormRequest;

final class SendNotificationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'channel' => ['required', 'string', 'in:sms,email'],
            'message' => ['required', 'string', 'min:1', 'max:5000'],
            'priority' => ['required', 'integer', 'min:1', 'max:3'],
            'recipient_ids' => ['required', 'array', 'min:1'],
            'recipient_ids.*' => ['required', 'string', 'min:1', 'max:255', 'distinct'],
        ];
    }

    public function toCommand(): SendNotificationsCommand
    {
        $validated = $this->validated();

        return new SendNotificationsCommand(
            channel: $validated['channel'],
            message: $validated['message'],
            priority: (int) $validated['priority'],
            recipientIds: array_values($validated['recipient_ids']),
            requestId: (string) $this->attributes->get('request_id'),
            idempotencyKey: $this->headers->get('Idempotency-Key'),
        );
    }
}
