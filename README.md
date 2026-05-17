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

- PHP `8.5.6`
- Laravel Framework `13.9.0`
- Laravel Octane `2.17.3`
- Swoole `6.2.1`
- PHP Redis extension `6.3.0`
- PostgreSQL `17.5`
- Redis `7.4.2`
- RabbitMQ `4.1.1-management`
- VictoriaMetrics `1.102.1`
- Grafana `11.5.2`
- Go `1.26.3`

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
make migrate
make down
make test
make logs
```

`make up` запускает Docker Compose окружение из `infra/docker-compose.yml`: Laravel Octane HTTP API, send worker, outbox worker, PostgreSQL, Redis, RabbitMQ, mock SMS provider, mock Email provider, VictoriaMetrics и Grafana. Миграции не выполняются автоматически при старте контейнеров; схема БД применяется явной командой `make migrate`.

Доступные локальные URL после запуска:

- API: `http://localhost:8080/api`
- Healthcheck: `http://localhost:8080/api/health`
- Metrics: `http://localhost:8080/api/metrics`
- RabbitMQ Management: `http://localhost:15672`
- VictoriaMetrics: `http://localhost:8428`
- Grafana: `http://localhost:3000`
- SMS provider: `http://localhost:8081`
- Email provider: `http://localhost:8082`

Outbox publisher внутри Laravel приложения запускается командой:

```bash
php artisan notifications:outbox:publish --daemon --limit=100 --sleep=2
```

RabbitMQ topology хранится в `infra/rabbitmq/definitions.json`; при подключении этих файлов в compose RabbitMQ создаст exchange, priority queues, retry queues и DLQ при старте.

Для локального окружения используются dev-образы с тестовыми зависимостями. Production target Laravel Dockerfile собирается без dev-зависимостей:

```bash
docker build -f infra/servicenotification/Dockerfile --target production -t notification-service/servicenotification:production apps/servicenotification
```

## Документация

- Архитектура: [docs/architecture.md](docs/architecture.md)
- OpenAPI: [docs/openapi.yaml](docs/openapi.yaml)
- Версии: [docs/versions.md](docs/versions.md)
