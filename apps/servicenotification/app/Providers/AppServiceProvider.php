<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Outbox\MessagePublisher;
use App\Application\Repository\NotificationRepository;
use App\Infrastructure\Messaging\RabbitMqMessagePublisher;
use App\Infrastructure\Persistence\DatabaseNotificationRepository;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(NotificationRepository::class, DatabaseNotificationRepository::class);
        $this->app->bind(MessagePublisher::class, RabbitMqMessagePublisher::class);
    }

    public function boot(): void
    {
        //
    }
}
