# Notification Service

Микросервис уведомлений принимает массовые SMS/Email рассылки, сохраняет намерение отправки в PostgreSQL, публикует задания через RabbitMQ и асинхронно доставляет сообщения через независимые mock providers.

Авторизация не входит в ответственность сервиса: вызывающий сервис считается внутренним доверенным актором.

## Структура репозитория

```text
apps/
  servicenotification/  Laravel Octane Notification Service
  smsprovider/          Mock SMS provider на Go
  emailprovider/        Mock Email provider на Go
docs/
  architecture.md       Архитектура и модель надежности
  openapi.yaml          Swagger/OpenAPI спецификация публичного API
  versions.md           Зафиксированные версии стека
infra/                  Docker, docker-compose, provisioning и инфраструктурные настройки
```

## Зафиксированные версии

Полная матрица находится в [docs/versions.md](docs/versions.md).

Ключевые версии:

- PHP `8.5.0`
- Laravel Framework `13.9.0`
- Laravel Octane `2.17.3`
- Swoole `6.0.2`
- PostgreSQL `17.5`
- Redis `7.4.2`
- RabbitMQ `4.1.1-management`
- VictoriaMetrics `1.102.1`
- Grafana `11.5.2`
- Go `1.24.4`

Floating tags вроде `latest` не используются.

## API

Основные endpoints описаны в [docs/openapi.yaml](docs/openapi.yaml):

- `POST /api/notifications/send` - запуск массовой SMS/Email рассылки.
- `GET /api/subscribers/{subscriberId}/notifications` - история и текущие статусы уведомлений подписчика.
- `POST /api/providers/{provider}/webhooks` - callback статусов доставки от mock providers.

Приоритет задается числом от `1` до `3`, где `3` - срочное транзакционное уведомление.

Статусы уведомлений:

- `queued`
- `sent`
- `delivered`
- `dropped`

## Команды разработки

Команды выполняются из корня проекта:

```bash
make up
make down
make test
make test-integration
make migrate
make logs
make lint
make swagger-validate
```

На текущем этапе `infra/docker-compose.yml` и приложения будут добавлены следующими задачами. Makefile уже фиксирует единый интерфейс команд и путь к compose-файлу.

## Документация

- Архитектура: [docs/architecture.md](docs/architecture.md)
- OpenAPI: [docs/openapi.yaml](docs/openapi.yaml)
- Версии: [docs/versions.md](docs/versions.md)
