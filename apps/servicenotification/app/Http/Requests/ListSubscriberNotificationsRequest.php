<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Application\Query\ListSubscriberNotificationsQuery;
use App\Application\Support\CursorCodec;
use Illuminate\Foundation\Http\FormRequest;

final class ListSubscriberNotificationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', 'in:queued,sent,delivered,dropped'],
            'channel' => ['sometimes', 'string', 'in:sms,email'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => [
                'sometimes',
                'string',
                'min:1',
                'max:512',
                function (string $attribute, mixed $value, callable $fail): void {
                    if (! is_string($value) || CursorCodec::decode($value) === null) {
                        $fail('The cursor is invalid.');
                    }
                },
            ],
        ];
    }

    public function toQuery(string $subscriberId): ListSubscriberNotificationsQuery
    {
        $validated = $this->validated();

        return new ListSubscriberNotificationsQuery(
            subscriberId: $subscriberId,
            requestId: (string) $this->attributes->get('request_id'),
            status: $validated['status'] ?? null,
            channel: $validated['channel'] ?? null,
            limit: (int) ($validated['limit'] ?? 50),
            cursor: $validated['cursor'] ?? null,
        );
    }
}
