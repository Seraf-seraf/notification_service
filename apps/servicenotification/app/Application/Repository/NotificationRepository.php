<?php

declare(strict_types=1);

namespace App\Application\Repository;

use App\Application\Command\SendNotificationsCommand;
use App\Application\DTO\SendNotificationsResultDto;
use App\Application\DTO\SubscriberNotificationsPageDto;
use App\Application\Query\ListSubscriberNotificationsQuery;

interface NotificationRepository
{
    public function createBatch(SendNotificationsCommand $command): SendNotificationsResultDto;

    public function listSubscriberNotifications(ListSubscriberNotificationsQuery $query): SubscriberNotificationsPageDto;
}
