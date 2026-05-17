# Service Notification

Laravel Octane приложение Notification Service.

## Runtime

- PHP `8.5.0`
- Laravel Framework `13.9.0`
- Laravel Octane `2.17.3`
- Octane server: `swoole`

## Настроено

- API routing через `routes/api.php`.
- Healthcheck: `GET /api/health` и стандартный Laravel `GET /up`.
- PostgreSQL как основной `DB_CONNECTION`.
- Redis для cache/session.
- RabbitMQ конфиг в `config/rabbitmq.php`.
- Outbox publisher: `php artisan notifications:outbox:publish --limit=100`.
- Provider/retry настройки в `config/notification.php`.
- VictoriaMetrics-related настройки в `config/observability.php`.
- JSON logs в stderr через Monolog `JsonFormatter`.
- Базовые слои приложения: `Http`, `Application`, `Domain`, `Infrastructure`, `Providers`, `Queue`, `Observability`.

## Локальная проверка

```bash
php artisan route:list --path=api
php artisan test
```

Swoole extension устанавливается в Docker-образе на следующем этапе инфраструктуры.
