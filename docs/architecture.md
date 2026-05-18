# Notification Service Architecture

## 1. Назначение

Notification Service принимает запросы на массовую и транзакционную отправку SMS/Email уведомлений, сохраняет намерение отправки в PostgreSQL, публикует задания в RabbitMQ и асинхронно доставляет сообщения через независимые provider adapters.

Авторизация находится вне ответственности сервиса. Сервис ожидает, что вызывающий upstream уже проверил права инициатора.

## 2. Акторы и внешние системы

| Актор / система       | Роль                                                                                                                                                                |
|-----------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Calling Service       | Внутренний инициатор рассылки. Вызывает HTTP API без авторизации на стороне Notification Service. Передает канал, текст, priority, recipient_ids и idempotency key. |
| Notification HTTP API | Laravel Octane API. Валидирует запросы, создает batch/notifications, возвращает статус приема, предоставляет историю уведомлений подписчика.                        |
| PostgreSQL            | Основное хранилище batches, notifications, истории статусов, idempotency keys и outbox-событий. Источник истины по статусам.                                        |
| Redis                 | Быстрый кэш и координация: request context, short-lived locks, rate/guard counters при необходимости, временные ключи для воркеров. Не является источником истины.  |
| RabbitMQ              | Персистентный брокер заданий отправки с приоритетами, retry и dead-letter queues.                                                                                   |
| Send Workers          | Воркеры отправки. Читают RabbitMQ, выбирают provider adapter по channel, вызывают mock provider, обновляют статусы и подтверждают сообщения manual ack.             |
| Mock SMS Provider     | Go HTTP-сервер, имитирующий SMS gateway. Принимает send request, асинхронно меняет provider-side status, отдает status через status endpoint или webhook.           |
| Mock Email Provider   | Go HTTP-сервер, имитирующий Email gateway с тем же контрактом, что SMS provider.                                                                                    |
| Status Updater        | Webhook endpoint, который получает delivered/dropped от provider и обновляет PostgreSQL. Provider-side status endpoint оставлен для будущего polling adapter.       |
| VictoriaMetrics       | Хранилище метрик HTTP API, воркеров, provider calls, очередей, retry/DLQ и статусов уведомлений.                                                                    |
| Grafana               | Дашборды по метрикам Notification Service и инфраструктуры.                                                                                                         |

## 3. Контекстная схема

```mermaid
flowchart LR
    caller[Calling Service]
    api[Notification HTTP API<br/>Laravel Octane]
    pg[(PostgreSQL)]
    redis[(Redis)]
    rabbit[(RabbitMQ)]
    worker[Send Workers]
    status[Status Updater<br/>webhook]
    sms[Mock SMS Provider<br/>Go]
    email[Mock Email Provider<br/>Go]
    vm[(VictoriaMetrics)]
    grafana[Grafana]

    caller -->|POST /api/notifications/send| api
    caller -->|GET /api/subscribers/{id}/notifications| api
    api --> pg
    api --> redis
    api -->|publish/outbox| rabbit
    rabbit --> worker
    worker --> pg
    worker --> redis
    worker --> sms
    worker --> email
    sms -->|webhook result| status
    email -->|webhook result| status
    status --> pg
    api --> vm
    worker --> vm
    status --> vm
    vm --> grafana
```

## 4. Внутренние компоненты приложения

| Компонент                     | Ответственность                                                                                                   |
|-------------------------------|-------------------------------------------------------------------------------------------------------------------|
| HTTP Controllers              | Тонкий слой приема запросов и выдачи response DTO/resources.                                                      |
| Request Validation            | Проверка channel `sms/email`, priority `1..3`, message, recipient_ids, idempotency key и `X-Request-Id`.          |
| RequestId Middleware          | Принимает `X-Request-Id` или генерирует новый. Прокидывает его в response, логи, queue messages и provider calls. |
| Application Services          | Оркестрация use cases: create batch, query subscriber history, process send job, update provider status.          |
| Domain Layer                  | Batch, Notification, status transitions, priority, channel, idempotency policy.                                   |
| Infrastructure Repositories   | Работа с PostgreSQL, транзакциями, уникальными ограничениями и блокировками.                                      |
| Outbox / Publisher            | Надежная публикация сообщений в RabbitMQ после фиксации данных в PostgreSQL.                                      |
| Provider Gateway Interface    | Абстракция отправки уведомлений. Домен и application layer не зависят от конкретного HTTP-провайдера.             |
| SMS / Email Provider Adapters | Реализации Provider Gateway Interface для mock SMS и mock Email providers.                                        |
| Metrics Middleware / Exporter | Метрики HTTP latency, status codes, worker results, retry, dropped, provider latency и queue lag.                 |

## 5. Данные и статусы

Основные сущности:

- `notification_batches`: один API-запрос на рассылку.
- `notifications`: одно уведомление одному подписчику.
- `notification_status_history`: история переходов статусов.
- `idempotency_keys`: защита от повторного создания batch по дублирующему запросу.
- `outbox_messages`: надежная публикация событий в RabbitMQ.

Статусы Notification Service:

| Статус      | Значение                                           |
|-------------|----------------------------------------------------|
| `queued`    | Уведомление принято, сохранено и ожидает отправки. |
| `sent`      | Уведомление передано provider gateway.             |
| `delivered` | Provider подтвердил доставку.                      |
| `dropped`   | Доставка невозможна или исчерпаны retry-попытки.   |

Переходы статусов монотонные:

```text
queued -> sent -> delivered
queued -> sent -> dropped
queued -> dropped
```

Откат финальных статусов `delivered` и `dropped` запрещен.

## 6. RabbitMQ topology

Топология RabbitMQ описана в `infra/rabbitmq/definitions.json` и загружается RabbitMQ при старте через `infra/rabbitmq/rabbitmq.conf`.

Сервис использует durable topology:

- `notifications.exchange` для заданий отправки.
- `notifications.retry.exchange` для отложенных повторов.
- `notifications.dlx` для сообщений, которые должны уйти в dead-letter queue.
- Durable send queues по каналам: `notifications.sms.send` и `notifications.email.send`.
- Очереди отправки поддерживают priority `1..3`, где `3` - самый высокий приоритет.
- Messages публикуются как persistent.
- Consumers используют manual ack.
- Prefetch ограничивается, чтобы сообщения priority `3` могли обгонять priority `1` и `2`.
- Retry queues используют TTL/backoff `30, 120, 300, 900, 1800` секунд и dead-letter routing обратно в channel-specific send queue.
- DLQ `notifications.dlq` хранит сообщения, которые не удалось обработать после лимита попыток или которые были dead-lettered из send queue.

Основные bindings:

| Exchange                       | Routing key                      | Queue                              |
|--------------------------------|----------------------------------|------------------------------------|
| `notifications.exchange`       | `notifications.sms.send`         | `notifications.sms.send`           |
| `notifications.exchange`       | `notifications.email.send`       | `notifications.email.send`         |
| `notifications.retry.exchange` | `notifications.sms.retry.30`     | `notifications.sms.retry.30s`      |
| `notifications.retry.exchange` | `notifications.sms.retry.120`    | `notifications.sms.retry.120s`     |
| `notifications.retry.exchange` | `notifications.sms.retry.300`    | `notifications.sms.retry.300s`     |
| `notifications.retry.exchange` | `notifications.sms.retry.900`    | `notifications.sms.retry.900s`     |
| `notifications.retry.exchange` | `notifications.sms.retry.1800`   | `notifications.sms.retry.1800s`    |
| `notifications.retry.exchange` | `notifications.email.retry.30`   | `notifications.email.retry.30s`    |
| `notifications.retry.exchange` | `notifications.email.retry.120`  | `notifications.email.retry.120s`   |
| `notifications.retry.exchange` | `notifications.email.retry.300`  | `notifications.email.retry.300s`   |
| `notifications.retry.exchange` | `notifications.email.retry.900`  | `notifications.email.retry.900s`   |
| `notifications.retry.exchange` | `notifications.email.retry.1800` | `notifications.email.retry.1800s`  |
| `notifications.dlx`            | `notifications.dlq`              | `notifications.dlq`                |

Приоритет задает инициатор API:

| Priority | Смысл                                                                   |
|----------|-------------------------------------------------------------------------|
| `1`      | Несрочная / маркетинговая рассылка.                                     |
| `2`      | Обычное сервисное уведомление.                                          |
| `3`      | Срочное транзакционное уведомление. Должно обгонять priority `1` и `2`. |

## 7. Основной поток массовой рассылки

1. Calling Service отправляет `POST /api/notifications/send` с `X-Request-Id`, `Idempotency-Key`, `channel`, `message`, `priority` и `recipient_ids`.
2. RequestId Middleware принимает `X-Request-Id` или генерирует новый.
3. API валидирует payload. Авторизация не выполняется.
4. Application Service проверяет idempotency key:
   - если такой ключ уже был успешно обработан с тем же payload, возвращается прежний результат;
   - если payload отличается, возвращается conflict.
5. В PostgreSQL в одной транзакции создаются batch, notifications со статусом `queued`, записи status history и outbox messages.
6. API возвращает `202 Accepted` с `batch_id`, количеством уведомлений и текущим статусом приема.
7. Outbox Publisher, запускаемый командой `php artisan notifications:outbox:publish`, публикует persistent messages в RabbitMQ с `notification_id`, `batch_id`, `channel`, `priority`, `attempt`, `request_id`.
8. Send Worker получает сообщение, блокирует notification на время обработки, выбирает SMS или Email adapter и вызывает provider.
9. При успешной передаче provider возвращает `provider_message_id`; notification переходит в `sent`, status history пополняется, RabbitMQ message подтверждается manual ack.
10. Финальный статус `delivered` или `dropped` приходит позднее через provider webhook. Mock providers также отдают status endpoint, но polling job в Laravel приложении сейчас не реализован.

## 8. Поток транзакционного уведомления с высоким приоритетом

1. Calling Service отправляет тот же send endpoint, но с `priority = 3`.
2. API сохраняет notification как `queued` и публикует сообщение с RabbitMQ priority `3`.
3. RabbitMQ отдает priority `3` раньше сообщений priority `1` и `2`, которые еще не взяты воркерами.
4. Send Workers работают с малым prefetch, чтобы низкоприоритетные сообщения не занимали большие локальные буферы consumer'ов.
5. Для масштабирования допускается отдельный worker pool с большим числом replicas для срочного трафика, но контракт API остается тем же.

## 9. Webhook поток обновления статуса provider

Webhook, то есть HTTP callback от provider, не считается универсальной возможностью любого реального провайдера. Это capability конкретного provider adapter. Для mock SMS и mock Email providers поведение задается конфигурацией, чтобы интеграционные тесты имели предсказуемый сценарий.

Конфигурация mock provider:

- `WEBHOOK_ENABLED=true/false` - отправлять ли webhook в Notification Service после асинхронной обработки.
- `WEBHOOK_URL=http://servicenotification/api/providers/{provider}/webhooks` - endpoint для delivery status.
- `STATUS_MODE=success|temporary_failure|permanent_failure|mixed` - детерминированный сценарий обработки.
- `PROCESSING_DELAY_MS=...` - задержка перед финальным статусом.

Если `WEBHOOK_ENABLED=true`, mock provider сам отправляет финальный статус. Если `WEBHOOK_ENABLED=false`, финальный статус остается доступен только через provider-side status endpoint; отдельный polling job в Notification Service сейчас не реализован.

Для будущих real adapters правила зависят от возможностей провайдера:

- SMS gateways обычно поддерживают delivery report webhook.
- Email providers уровня SendGrid/Mailgun/Postmark/SES часто поддерживают event webhooks.
- Обычный SMTP/Gmail/Mail.ru может дать только факт принятия письма; финальные ошибки обычно приходится определять через bounce-письма или отдельный polling mailbox.

### Webhook

1. Provider после асинхронной обработки вызывает webhook endpoint Notification Service.
2. Webhook payload содержит `provider_message_id`, provider status, timestamp и correlation/request id при наличии.
3. Status Updater находит notification по `provider_message_id`.
4. Provider status маппится во внутренний статус:
   - provider `accepted/processing` не меняет финальный статус после `sent`;
   - provider `delivered` -> `delivered`;
   - provider `failed/permanent_failed/invalid_recipient` -> `dropped`.
5. Переход применяется идемпотентно и монотонно. Дубликаты webhook не создают противоречивые состояния.
6. В PostgreSQL добавляется запись в `notification_status_history`.

### Provider-side status endpoint

Mock providers реализуют `GET /api/v1/messages/{provider_message_id}`. Этот endpoint нужен для contract tests и будущего polling adapter. Текущая Laravel-реализация обновляет финальные статусы через webhook endpoint `/api/providers/{provider}/webhooks`.

## 10. Контракты провайдеров и независимость от шлюзов

Notification Service не должен зависеть от конкретного SMS или Email шлюза. Application layer работает только с доменным интерфейсом отправки, а реальные HTTP-вызовы изолированы в provider adapters.

### 10.1. Provider Gateway Interface

Интерфейс отправки уведомлений внутри приложения:

```php
interface NotificationProviderClient
{
    public function send(ProviderSendRequest $request): ProviderSendResult;
}
```

Выбор клиента выполняется через registry:

```php
interface NotificationProviderRegistry
{
    public function forChannel(string $channel): NotificationProviderClient;
}
```

Назначение методов:

| Метод       | Назначение                                                                                                     |
|-------------|----------------------------------------------------------------------------------------------------------------|
| `send`      | Передает одно уведомление во внешний provider. Возвращает `provider_message_id` или классифицированную ошибку. |
| `forChannel` | Выбирает provider client по каналу `sms` или `email`.                                                         |

Доменная логика не знает URL, headers, retry-коды и особенности конкретного HTTP provider. Она получает только нормализованный результат adapter'а.

### 10.2. Provider adapters

| Adapter                | Канал   | Provider            | Ответственность                                                                                     |
|------------------------|---------|---------------------|-----------------------------------------------------------------------------------------------------|
| `SmsProviderAdapter`   | `sms`   | Mock SMS Provider   | Собирает SMS HTTP request, вызывает mock SMS server, классифицирует ошибки и provider statuses.     |
| `EmailProviderAdapter` | `email` | Mock Email Provider | Собирает Email HTTP request, вызывает mock Email server, классифицирует ошибки и provider statuses. |

Выбор adapter'а выполняется по `channel`. Добавление нового шлюза, например реального SMS provider, должно требовать новую реализацию interface и изменение configuration binding, а не изменение доменной логики worker'а.

### 10.3. ProviderSendRequest

Внутренний DTO, который worker передает adapter'у:

```json
{
  "notification_id": "1b5db0e8-8f30-5c93-9f7f-5a6d6b6c1c62",
  "subscriber_id": "user-10001",
  "channel": "sms",
  "message": "Ваш код: 1234",
  "priority": 3,
  "request_id": "req-7bb7f2e8"
}
```

Для HTTP mock providers adapter использует `notification_id` как `Idempotency-Key`, чтобы повторная доставка RabbitMQ message не создавала дубль на стороне provider. `webhook_url` формируется adapter'ом из `PROVIDER_WEBHOOK_URL` или route `/api/providers/{provider}/webhooks`.

### 10.4. Mock provider HTTP send request

Mock SMS и Email providers имеют одинаковый базовый contract. Отличается только `channel` и канал-специфичные поля.

Endpoint:

```text
POST /api/v1/messages
```

Headers:

```text
Content-Type: application/json
X-Request-Id: <request_id>
Idempotency-Key: <notification_id>
```

Body:

```json
{
  "message_id": "1b5db0e8-8f30-5c93-9f7f-5a6d6b6c1c62",
  "recipient_id": "user-10001",
  "channel": "sms",
  "text": "Ваш код: 1234",
  "priority": 3,
  "webhook_url": "http://servicenotification/api/providers/sms/webhooks",
  "metadata": {
    "request_id": "req-7bb7f2e8"
  }
}
```

Правила mock provider:

- повторный request с тем же `Idempotency-Key` возвращает тот же `provider_message_id`;
- если `WEBHOOK_ENABLED=true`, provider после асинхронной обработки отправляет webhook на `webhook_url` или на `WEBHOOK_URL` из config;
- если `WEBHOOK_ENABLED=false`, финальный статус доступен только через provider-side status endpoint;
- `STATUS_MODE` управляет финальным статусом и ошибками для интеграционных тестов.

### 10.5. Mock provider send response

Успешное принятие provider'ом:

```http
HTTP/1.1 202 Accepted
Content-Type: application/json
```

```json
{
  "provider_message_id": "smsmsg-000001",
  "status": "accepted",
  "deduplicated": false
}
```

Идемпотентный повтор:

```http
HTTP/1.1 200 OK
Content-Type: application/json
```

```json
{
  "provider_message_id": "smsmsg-000001",
  "status": "accepted",
  "deduplicated": true
}
```

Постоянная ошибка:

```http
HTTP/1.1 422 Unprocessable Entity
Content-Type: application/json
```

```json
{
  "error": "invalid_recipient",
  "message": "Recipient phone or email does not exist"
}
```

Временная ошибка:

```http
HTTP/1.1 503 Service Unavailable
Content-Type: application/json
```

```json
{
  "error": "provider_unavailable",
  "retry_after_seconds": 30
}
```

### 10.6. Provider-side status request

Endpoint:

```text
GET /api/v1/messages/{provider_message_id}
```

Response:

```json
{
  "provider_message_id": "smsmsg-000001",
  "message_id": "1b5db0e8-8f30-5c93-9f7f-5a6d6b6c1c62",
  "status": "delivered",
  "reason": null,
  "updated_at": "2026-05-16T10:15:30Z"
}
```

### 10.7. Provider delivery webhook

Webhook endpoint в Notification Service:

```text
POST /api/providers/{provider}/webhooks
```

Body:

```json
{
  "provider_message_id": "smsmsg-000001",
  "message_id": "1b5db0e8-8f30-5c93-9f7f-5a6d6b6c1c62",
  "status": "delivered",
  "reason": null,
  "occurred_at": "2026-05-16T10:15:30Z",
  "metadata": {
    "request_id": "req-7bb7f2e8"
  }
}
```

Webhook должен обрабатываться идемпотентно. Дубликат webhook с тем же `provider_message_id` и `status` не должен создавать второй доменный переход статуса.

### 10.8. Provider-side statuses

| Provider status     | Значение у provider'а                                     | Финальный |
|---------------------|-----------------------------------------------------------|-----------|
| `accepted`          | Provider принял сообщение на обработку.                   | Нет       |
| `processing`        | Provider асинхронно обрабатывает сообщение.               | Нет       |
| `delivered`         | Provider подтвердил доставку.                             | Да        |
| `temporary_failed`  | Временная ошибка provider, можно повторить отправку.      | Нет       |
| `permanent_failed`  | Постоянная ошибка доставки.                               | Да        |
| `invalid_recipient` | Номер/email не существует или некорректен.                | Да        |
| `expired`           | Provider не смог доставить сообщение за допустимое время. | Да        |

### 10.9. Маппинг provider statuses

| Текущий status    | Provider result/status                 | Новый status                 | Retry | Комментарий                                                                          |
|-------------------|----------------------------------------|------------------------------|-------|--------------------------------------------------------------------------------------|
| `queued`          | HTTP `202` + `accepted`                | `sent`                       | Нет   | Сообщение принято provider'ом.                                                       |
| `queued`          | HTTP `200` + `accepted` + deduplicated | `sent`                       | Нет   | Повтор provider call, provider вернул прежний `provider_message_id`.                 |
| `queued`          | `temporary_failed`, HTTP `429/500/503` | `queued`                     | Да    | Provider не принял сообщение, уходит в retry policy с backoff.                       |
| `queued`          | `invalid_recipient`                    | `dropped`                    | Нет   | Постоянная бизнес-ошибка, retry не нужен.                                            |
| `sent`            | `processing`                           | `sent`                       | Нет   | Current status не откатывается, ожидаем финальный result.                            |
| `sent`            | `delivered`                            | `delivered`                  | Нет   | Финальный успешный статус.                                                           |
| `sent`            | `permanent_failed`                     | `dropped`                    | Нет   | Финальная ошибка доставки.                                                           |
| `sent`            | `invalid_recipient`                    | `dropped`                    | Нет   | Provider определил постоянную ошибку после принятия сообщения.                       |
| `sent`            | `expired`                              | `dropped`                    | Нет   | Provider исчерпал окно доставки.                                                     |
| любой нефинальный | Неизвестный status                     | Без смены финального статуса | Да    | Ограниченный retry; после лимита DLQ и `dropped` с reason `provider_contract_error`. |

### 10.10. Граница зависимости

Application Service и worker зависят от:

- `NotificationProviderClient`;
- `NotificationProviderRegistry`;
- DTO `ProviderSendRequest`;
- DTO `ProviderSendResult`;
- нормализованных error/status enums.

Application Service и worker не зависят от:

- конкретных HTTP URL provider'а;
- формата provider-specific headers;
- raw JSON response provider'а;
- правил авторизации конкретного шлюза;
- особенностей SMS или Email vendor SDK.

Все provider-specific детали находятся внутри adapter'а и покрываются contract/integration tests.

## 11. Retry и DLQ поток

1. Send Worker получает сообщение из RabbitMQ.
2. Если provider вернул временную ошибку или недоступен, worker не переводит notification в финальный `dropped`.
3. Attempt увеличивается, ошибка логируется с `request_id`, `notification_id`, `channel`, `priority`.
4. Сообщение отправляется в retry queue с backoff.
5. После TTL RabbitMQ возвращает сообщение в send queue.
6. Если попытка успешна, notification переходит в `sent`, message подтверждается manual ack.
7. Если лимит attempts исчерпан, notification переводится в `dropped`, пишется status history, а техническое сообщение фиксируется в DLQ или как exhausted retry.
8. Permanent provider errors, например несуществующий email/номер, сразу переводят notification в `dropped` без лишних retry.

## 12. Сценарий запроса истории уведомлений подписчика

1. Calling Service вызывает `GET /api/subscribers/{subscriberId}/notifications`.
2. RequestId Middleware добавляет request id в контекст и response.
3. API читает PostgreSQL по `subscriber_id`.
4. Ответ содержит список notifications подписчика:
   - `notification_id`;
   - `batch_id`;
   - `channel`;
   - `message`;
   - `priority`;
   - `current_status`;
   - timestamps;
   - полную историю статусов в хронологическом порядке.
5. При росте объема данных endpoint должен поддерживать пагинацию и фильтры по channel/status/date range.

## 13. Модель надежности и доставки

Цель модели: не потерять принятое уведомление, не отправить одно и то же бизнес-уведомление повторно из-за дубликатов запросов или повторной доставки RabbitMQ message и корректно различать временные технические ошибки от финальных ошибок доставки.

### 13.1. Уровни гарантий

| Уровень                       | Гарантия                                                       | Как достигается                                                                                               |
|-------------------------------|----------------------------------------------------------------|---------------------------------------------------------------------------------------------------------------|
| Прием API-запроса             | Идемпотентное создание batch                                   | `Idempotency-Key`, hash payload, уникальные ограничения PostgreSQL.                                           |
| Сохранение намерения отправки | Не теряем notification после `202 Accepted`                    | PostgreSQL transaction: batch, notifications, status history и outbox создаются атомарно.                     |
| Публикация в очередь          | Не теряем сообщение между PostgreSQL commit и RabbitMQ publish | Transactional outbox и отдельный outbox publisher с повторной публикацией.                                    |
| Доставка worker'у             | At-least-once                                                  | Durable RabbitMQ queues, persistent messages, manual ack.                                                     |
| Бизнес-обработка              | Exactly-once на уровне бизнес-логики                           | Детерминированные identifiers, уникальные ключи, идемпотентные provider calls и монотонные переходы статусов. |
| Финальный статус              | Идемпотентное обновление                                       | Уникальная история переходов и запрет отката финальных статусов.                                              |

### 13.2. At-least-once через RabbitMQ

RabbitMQ используется как персистентный брокер заданий отправки:

- exchange и queues объявляются как durable;
- send/retry/DLQ queues не используют auto-delete;
- каждое сообщение публикуется с delivery mode `persistent`;
- publisher использует confirms, чтобы понимать, что RabbitMQ принял сообщение;
- consumers работают с manual ack;
- ack отправляется только после успешной фиксации результата обработки в PostgreSQL;
- при падении worker'а до ack RabbitMQ вернет сообщение в очередь и доставит повторно;
- prefetch ограничен, чтобы уменьшить число сообщений, зависших у умершего worker'а, и сохранить приоритетность обработки.

Эта модель допускает повторную доставку одного RabbitMQ message. Поэтому worker обязан быть идемпотентным.

### 13.3. Transactional outbox

API не публикует сообщение в RabbitMQ напрямую внутри HTTP request как единственную точку надежности. Вместо этого используется outbox:

1. В одной PostgreSQL transaction создаются:
   - `notification_batches`;
   - `notifications`;
   - начальные записи `notification_status_history` со статусом `queued`;
   - `outbox_messages` для отправки каждой notification.
2. После commit отдельный outbox publisher читает неопубликованные записи.
3. Publisher публикует persistent message в RabbitMQ.
4. После publisher confirm запись `outbox_messages` помечается как published.
5. Если RabbitMQ временно недоступен, outbox запись остается в PostgreSQL и будет опубликована повторно.

Так сервис не теряет notification между успешным ответом API и публикацией в RabbitMQ.

### 13.4. Exactly-once на уровне бизнес-логики

Физически RabbitMQ дает at-least-once, поэтому exactly-once реализуется на уровне доменных правил.

#### Idempotency API

Calling Service передает `Idempotency-Key` на `POST /api/notifications/send`. Ключ генерируется вызывающим сервисом, а Notification Service только проверяет его уникальность и связывает с результатом первого успешного запроса.

Правила:

- ключ должен быть UUID v4 или другой случайной строкой с энтропией не ниже 128 бит;
- ключ уникален в рамках endpoint;
- вместе с ключом сохраняется `payload_hash`;
- `payload_hash` считается как `SHA-256` от canonical JSON payload;
- повтор с тем же ключом и тем же payload возвращает ранее созданный `batch_id` и не создает новые notifications;
- повтор с тем же ключом, но другим payload возвращает `409 Conflict`;
- outbox messages повторно не создаются для идемпотентного повтора.

Canonical JSON payload включает только бизнес-поля запроса:

- `channel`;
- `message`;
- `priority`;
- `recipient_ids` в исходном порядке, потому что порядок получателей считается частью запроса.

В `payload_hash` не входят:

- `X-Request-Id`;
- технические headers;
- timestamps;
- idempotency key.

Срок хранения idempotency key: `24h` по умолчанию, настраивается через `IDEMPOTENCY_TTL_HOURS`. После истечения TTL ключ может быть удален background cleanup job. Для истории отправок и статусов это не опасно: batch и notifications остаются в PostgreSQL, удаляется только возможность распознать повтор старого API-запроса как идемпотентный retry.

Минимальные ограничения PostgreSQL:

```text
unique(endpoint, idempotency_key)
```

`payload_hash`, `batch_id`, HTTP status первого ответа, короткий response body и `expires_at` хранятся в строке idempotency key. При повторе до `expires_at` сервис сравнивает hash. Если ключ тот же, а hash отличается, сервис возвращает `409 Conflict`.

#### Детерминированные identifiers

Идентификаторы должны позволять безопасно распознавать повторную обработку:

- `batch_id` генерируется один раз при первом успешном idempotency request и сохраняется в `idempotency_keys`;
- `notification_id` детерминированно связан с `batch_id + recipient_id`, например UUID v5 с сохраненным unique constraint;
- `message_id` для RabbitMQ/outbox равен `notification_id` или производному `notification_id + attempt`;
- `notification_id` передается mock provider как HTTP `Idempotency-Key`, если provider contract это поддерживает.

Минимальные ограничения PostgreSQL:

```text
unique(batch_id, recipient_id)
unique(notification_id)
unique(provider_message_id) where provider_message_id is not null
unique(outbox_message_id)
```

#### Идемпотентный worker

Перед вызовом provider worker проверяет состояние notification:

- если status `delivered` или `dropped`, сообщение ack'ается без повторной отправки;
- если status `sent` и есть `provider_message_id`, сообщение ack'ается без повторной отправки provider;
- если status `queued`, worker берет блокировку строки notification `FOR UPDATE SKIP LOCKED` или короткий Redis lock и выполняет отправку;
- после успешного provider response worker сохраняет `provider_message_id`, переводит статус в `sent`, пишет history и только потом ack'ает RabbitMQ message.

Если worker упал после provider call, но до сохранения `sent`, возможен повторный вызов provider. Для снижения риска mock provider принимает `notification_id` как idempotency key и возвращает тот же `provider_message_id` при повторе. Для real provider это зависит от capability adapter'а; если provider не поддерживает idempotency key, exactly-once снаружи гарантировать нельзя, только at-least-once + внутренняя защита от повторов после сохранения `sent`.

#### Идемпотентные переходы статусов

Статус notification меняется только вперед:

```text
queued -> sent
queued -> dropped
sent -> delivered
sent -> dropped
```

Правила:

- `delivered` и `dropped` финальные;
- повторный webhook result с тем же статусом не создает логического дубля;
- webhook `sent/processing` не откатывает `delivered` или `dropped`;
- webhook `dropped` после `delivered` игнорируется или сохраняется как provider anomaly без изменения current status;
- каждая успешная смена current status добавляет запись в `notification_status_history`.

Минимальное ограничение для истории:

```text
unique(notification_id, status)
```

Если потребуется хранить повторные provider events без смены статуса, они должны попадать в отдельную таблицу raw events, а не ломать доменную историю статусов.

### 13.5. Классификация ошибок

| Ситуация                                                                    | Тип ошибки                | Действие                                                                            |
|-----------------------------------------------------------------------------|---------------------------|-------------------------------------------------------------------------------------|
| Provider timeout, connection refused, 5xx                                   | Временная техническая     | Retry с backoff.                                                                    |
| RabbitMQ publish недоступен                                                 | Временная техническая     | Outbox запись остается unpublished, publisher повторяет.                            |
| PostgreSQL deadlock/lock timeout в worker                                   | Временная техническая     | Nack/retry, без смены финального статуса.                                           |
| Provider rate limit `429`                                                   | Временная техническая     | Retry с увеличенным backoff, учитывать `Retry-After`, если есть.                    |
| Provider response `invalid_recipient`, `unknown_phone`, `mailbox_not_found` | Постоянная бизнес-ошибка  | `dropped` без retry.                                                                |
| Некорректный payload provider contract                                      | Техническая/контрактная   | Retry ограниченно; после лимита DLQ и `dropped` с reason `provider_contract_error`. |
| Provider принял сообщение, но позже сообщил failed/undeliverable            | Финальная ошибка доставки | `sent -> dropped`, без повторной отправки всего batch.                              |

Retry всегда применяется к конкретной `notification`, а не ко всему batch. Один плохой recipient не должен приводить к повторной отправке успешным получателям.

### 13.6. Retry policy

Базовые параметры:

| Параметр           | Значение по умолчанию                                                                   |
|--------------------|-----------------------------------------------------------------------------------------|
| `max_attempts`     | `5`                                                                                     |
| `initial_backoff`  | `10s`                                                                                   |
| `backoff_strategy` | exponential backoff                                                                     |
| `backoff_sequence` | `10s`, `30s`, `2m`, `5m`, `15m`                                                         |
| `jitter`           | `+-20%`, чтобы избежать синхронных повторов                                             |
| `worker_timeout`   | меньше HTTP timeout provider adapter'а и общего лимита обработки job, задается в config |

Алгоритм:

1. Worker обрабатывает RabbitMQ message с `attempt`.
2. При временной ошибке attempt увеличивается.
3. Если `attempt < max_attempts`, сообщение публикуется в retry queue с TTL/backoff.
4. После TTL RabbitMQ dead-letter'ит сообщение обратно в send queue.
5. Если `attempt >= max_attempts`, notification переводится в `dropped`, пишется status history с reason, техническое сообщение отправляется в DLQ или помечается как exhausted.

### 13.7. Dead-letter queue

DLQ используется для сообщений, которые сервис не смог корректно обработать после допустимого числа попыток.

В DLQ попадают:

- исчерпанные временные ошибки provider/RabbitMQ/DB;
- ошибки контракта provider после лимита retry;
- неожиданные exceptions worker'а после лимита retry;
- сообщения с некорректной структурой, которые невозможно связать с notification.

В DLQ не попадают как штатная ошибка:

- несуществующий номер;
- несуществующий email;
- provider-side финальное `undeliverable`;
- отказ после успешной передачи provider'у, если это нормальный delivery status.

Такие случаи фиксируются как `dropped` с reason в PostgreSQL и доступны через API истории подписчика.

DLQ message должен содержать:

- `notification_id`;
- `batch_id`;
- `channel`;
- `priority`;
- `attempt`;
- `request_id`;
- `error_class`;
- `error_message`;
- `failed_at`;
- исходный payload или ссылку на outbox message.

### 13.8. Правила финального `dropped`

Статус `dropped` ставится в трех случаях:

1. Permanent provider error до принятия сообщения:
   - `queued -> dropped`;
   - пример: provider сразу вернул `invalid_recipient`.
2. Provider delivery result после принятия сообщения:
   - `queued -> sent -> dropped`;
   - пример: SMS provider принял сообщение, но позже прислал webhook `undeliverable`.
3. Исчерпаны retry попытки при временной технической ошибке:
   - `queued -> dropped`;
   - reason содержит `retry_exhausted`;
   - техническая диагностическая запись уходит в DLQ.

Повторная отправка всего batch при `dropped` запрещена. Если нужен повтор после исправления данных получателя, вызывающий сервис должен создать новый send request с новым idempotency key.

### 13.9. Поведение при сбоях

| Сбой                                                     | Ожидаемое поведение                                                                                  |
|----------------------------------------------------------|------------------------------------------------------------------------------------------------------|
| API упал до commit PostgreSQL                            | Batch не создан, клиент может повторить запрос с тем же `Idempotency-Key`.                           |
| API упал после commit, но до ответа клиенту              | Повтор с тем же `Idempotency-Key` вернет уже созданный batch.                                        |
| RabbitMQ недоступен после создания notifications         | Outbox хранит unpublished messages, publisher повторит позже.                                        |
| Worker упал до provider call                             | RabbitMQ доставит message повторно.                                                                  |
| Worker упал после provider call, но до PostgreSQL update | Возможен повторный provider call; снижается через provider idempotency key на базе `notification_id`. |
| Worker упал после PostgreSQL update, но до ack           | RabbitMQ доставит message повторно; worker увидит `sent` и ack'нет без повторной отправки.           |
| Webhook пришел дважды                                    | Второй webhook идемпотентно игнорируется или сохраняется как raw event без изменения current status. |
| Два webhook события пришли одновременно                   | PostgreSQL lock/unique constraints сохраняют один монотонный переход.                                |

## 14. Наблюдаемость

Логи структурированные и JSON-совместимые. Базовые поля:

- `request_id`;
- `batch_id`;
- `notification_id`;
- `subscriber_id`;
- `channel`;
- `priority`;
- `status`;
- `attempt`;
- `provider`;
- `duration_ms`;

Метрики отправляются в VictoriaMetrics:

- HTTP request count и latency buckets по route/method/status code.
- Количество созданных batches и notifications.
- Worker processed/failed/retried/dropped.
- Provider latency и provider error count.
- Queue lag, retry queue depth, DLQ depth.
- Распределение notifications по статусам.

Grafana использует provisioning из `infra` и показывает состояние API, очередей, воркеров, providers и ошибок доставки.

## 15. Масштабирование и отказоустойчивость

- HTTP API масштабируется горизонтально через несколько Laravel Octane replicas.
- Send Workers масштабируются независимо от HTTP API.
- PostgreSQL остается источником истины; критичные операции выполняются в транзакциях.
- RabbitMQ хранит durable очереди и persistent messages.
- Redis используется только для ускорения и координации, потеря Redis не должна уничтожать источник истины.
- Provider adapters позволяют заменить mock providers на реальные шлюзы без изменения доменной логики.
- At-least-once обеспечивается RabbitMQ durable messages и manual ack.
- Exactly-once на уровне бизнес-логики достигается идемпотентными ключами, уникальными ограничениями и монотонными переходами статусов.

## 16. Границы ответственности

Notification Service отвечает за:

- прием команд на отправку уведомлений;
- хранение batch, notifications и истории статусов;
- публикацию заданий отправки;
- вызов provider adapters;
- retry/DLQ;
- idempotency;
- observability.

Notification Service не отвечает за:

- авторизацию вызывающего сервиса;
- хранение профилей подписчиков;
- выбор бизнес-аудитории рассылки;
- реальную доставку за пределами provider contract;
- управление секретами внешней платформы за пределами env/config.
