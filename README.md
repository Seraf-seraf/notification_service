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

### Примеры запросов

Запуск маркетинговой SMS рассылки:

```bash
curl -i -X POST http://localhost:8080/api/notifications/send \
  -H 'Content-Type: application/json' \
  -H 'X-Request-Id: req-readme-sms-001' \
  -H 'Idempotency-Key: readme-sms-marketing-001' \
  -d '{
    "channel": "sms",
    "message": "Скидка 20% на поездки до конца недели",
    "priority": 1,
    "recipient_ids": ["subscriber-10001", "subscriber-10002"]
  }'
```

Запуск срочного транзакционного Email:

```bash
curl -i -X POST http://localhost:8080/api/notifications/send \
  -H 'Content-Type: application/json' \
  -H 'X-Request-Id: req-readme-email-001' \
  -H 'Idempotency-Key: readme-email-transactional-001' \
  -d '{
    "channel": "email",
    "message": "Ваш код доступа 441122",
    "priority": 3,
    "recipient_ids": ["subscriber-20001"]
  }'
```

Запрос истории подписчика:

```bash
curl -i 'http://localhost:8080/api/subscribers/subscriber-10001/notifications?limit=50' \
  -H 'X-Request-Id: req-readme-history-001'
```

`Idempotency-Key` обязателен для `POST /api/notifications/send`. Повтор с тем же ключом и тем же JSON возвращает исходный `batch_id` и не создает новые `notifications`/`outbox_messages`; повтор с тем же ключом и другим payload возвращает `409 Conflict`.

Приоритеты обрабатываются строго по числу: `1` - несрочная/маркетинговая рассылка, `2` - обычное сервисное уведомление, `3` - срочное транзакционное уведомление. RabbitMQ queues настроены с `x-max-priority=3`, поэтому сообщения priority `3`, еще не взятые воркером, обгоняют priority `1` и `2`.

Temporary provider/RabbitMQ ошибки отправляются в retry с backoff `30,120,300,900,1800` секунд. Permanent provider errors, например несуществующий номер или email, сразу переводят notification в `dropped`. После исчерпания лимита попыток notification становится `dropped`, а техническое сообщение публикуется в DLQ.

## Команды разработки

Команды выполняются из корня проекта:

```bash
make up
make migrate
make down
make test
make test-integration
make test-e2e
make lint
make swagger-validate
make final-check
make logs
```

`make up` запускает Docker Compose окружение из `infra/docker-compose.yml`: Laravel Octane HTTP API, send worker, outbox worker, PostgreSQL, Redis, RabbitMQ, mock SMS provider, mock Email provider, VictoriaMetrics и Grafana. Миграции не выполняются автоматически при старте контейнеров; схема БД применяется явной командой `make migrate`.

Все проверки запускаются в контейнерах. Laravel тесты выполняются в сервисе `servicenotification`, Go provider tests - в фиксированном образе `golang:1.26.3-alpine3.22`, OpenAPI lint - в фиксированном образе `redocly/cli:1.34.5`.

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
- Тестовая стратегия: [docs/test-strategy.md](docs/test-strategy.md)
- Версии: [docs/versions.md](docs/versions.md)

## Mock providers

SMS и Email providers находятся в `apps/smsprovider` и `apps/emailprovider`. Это HTTP-серверы на Go с одинаковым контрактом:

- `POST /api/v1/messages` принимает одно сообщение, `X-Request-Id` и `Idempotency-Key`;
- `GET /api/v1/messages/{provider_message_id}` возвращает provider-side status;
- режим `mixed` детерминированно выбирает сценарий по recipient/text/metadata;
- `temporary_failure` возвращает временную ошибку для retry;
- `invalid_recipient`/`permanent_failure` возвращает постоянную ошибку;
- `async_permanent_failure` принимает сообщение, а затем асинхронно переводит его в permanent failure;
- webhook включается переменной `WEBHOOK_ENABLED`, URL задается из запроса или `WEBHOOK_URL`.

Ограничения mock providers: статусы хранятся в памяти процесса, отправка webhook выполняется best-effort без собственного retry, поведение предназначено для интеграционных сценариев, а не для production gateway.

## Финальный статус

Проект покрывает функциональные и нефункциональные требования из `AGENTS.md`: API массовой отправки и истории, приоритеты `1..3`, at-least-once через RabbitMQ, business idempotency, retry/DLQ, request id, метрики VictoriaMetrics, Grafana provisioning и запуск одной командой `docker compose -f infra/docker-compose.yml up --build --remove-orphans`.

Оставшийся компромисс: exactly-once реализован на уровне бизнес-логики через идемпотентность API, уникальные ограничения PostgreSQL, provider idempotency key и монотонные переходы статусов; физическая доставка RabbitMQ остается at-least-once.
