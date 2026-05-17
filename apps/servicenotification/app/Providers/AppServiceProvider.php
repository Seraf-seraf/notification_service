<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Delivery\DeliveryMessagePublisher;
use App\Application\Outbox\MessagePublisher;
use App\Application\Provider\NotificationProviderRegistry;
use App\Application\Repository\NotificationRepository;
use App\Infrastructure\Messaging\RabbitMqDeliveryMessagePublisher;
use App\Infrastructure\Messaging\RabbitMqMessagePublisher;
use App\Infrastructure\Persistence\DatabaseNotificationRepository;
use App\Infrastructure\Provider\ConfiguredNotificationProviderRegistry;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(NotificationRepository::class, DatabaseNotificationRepository::class);
        $this->app->bind(MessagePublisher::class, RabbitMqMessagePublisher::class);
        $this->app->bind(DeliveryMessagePublisher::class, RabbitMqDeliveryMessagePublisher::class);
        $this->app->bind(NotificationProviderRegistry::class, ConfiguredNotificationProviderRegistry::class);
    }

    public function boot(): void
    {
        //
    }
}
