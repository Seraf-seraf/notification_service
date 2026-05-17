<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Delivery\ProviderDeliveryStatusUpdater;
use App\Application\Service\NotificationApplicationService;
use App\Http\Requests\ListSubscriberNotificationsRequest;
use App\Http\Requests\ProviderWebhookRequest;
use App\Http\Requests\SendNotificationsRequest;
use App\Http\Resources\ApplicationDtoResource;

final class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationApplicationService $notifications,
        private readonly ProviderDeliveryStatusUpdater $providerStatuses,
    ) {}

    public function send(SendNotificationsRequest $request): ApplicationDtoResource
    {
        return new ApplicationDtoResource(
            dto: $this->notifications->send($request->toCommand()),
            status: 202,
        );
    }

    public function history(ListSubscriberNotificationsRequest $request, string $subscriberId): ApplicationDtoResource
    {
        return new ApplicationDtoResource(
            dto: $this->notifications->history($request->toQuery($subscriberId)),
        );
    }

    public function providerWebhook(ProviderWebhookRequest $request, string $provider): ApplicationDtoResource
    {
        return new ApplicationDtoResource(
            dto: $this->providerStatuses->update($request->toCommand($provider)),
            status: 202,
        );
    }
}
