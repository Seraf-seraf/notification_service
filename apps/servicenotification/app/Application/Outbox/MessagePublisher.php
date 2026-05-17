<?php

declare(strict_types=1);

namespace App\Application\Outbox;

interface MessagePublisher
{
    public function publish(OutboxMessage $message): void;
}
