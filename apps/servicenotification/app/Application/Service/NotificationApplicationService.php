<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Application\Command\SendNotificationsCommand;
use App\Application\DTO\SendNotificationsResultDto;
use App\Application\DTO\SubscriberNotificationsResultDto;
use App\Application\Query\ListSubscriberNotificationsQuery;
use App\Application\Repository\NotificationRepository;

final readonly class NotificationApplicationService
{
    public function __construct(
        private NotificationRepository $notifications,
    ) {}

    public function send(SendNotificationsCommand $command): SendNotificationsResultDto
    {
        return $this->notifications->createBatch($command);
    }

    public function history(ListSubscriberNotificationsQuery $query): SubscriberNotificationsResultDto
    {
        $page = $this->notifications->listSubscriberNotifications($query);

        return new SubscriberNotificationsResultDto(
            subscriberId: $query->subscriberId,
            notifications: $page->notifications,
            requestId: $query->requestId,
            limit: $query->limit,
            nextCursor: $page->nextCursor,
        );
    }
}
