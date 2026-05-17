# Worker delivery pattern

Этот файл описывает подход, который использован в блоке воркеров, retry и статусов доставки.

## Цель подхода

Разделить три ответственности:

- получение сообщения из RabbitMQ;
- бизнес-обработку notification;
- инфраструктурные детали: HTTP provider, retry queue, DLQ.

Такой подход нужен, чтобы application-код не зависел напрямую от конкретного SMS/Email provider и от деталей RabbitMQ.

## Общая схема

```text
RabbitMQ
  -> ConsumeNotificationMessagesCommand
      -> NotificationDeliveryProcessor
          -> NotificationSendHandler
              -> NotificationProviderRegistry
                  -> HttpNotificationProviderClient
              -> DB notifications/status_history
          -> DeliveryMessagePublisher
              -> RabbitMQ retry/DLQ
```

## Роли классов

### ConsumeNotificationMessagesCommand

Технический consumer RabbitMQ.

Он:

- подключается к RabbitMQ;
- читает сообщения из очередей `sms` и `email`;
- парсит payload в `NotificationSendPayload`;
- вызывает application processor;
- делает `ack` после обработки.

Он не решает бизнес-логику статусов.

### NotificationDeliveryProcessor

Координатор обработки одного сообщения.

Он:

- вызывает `NotificationSendHandler`;
- смотрит результат обработки;
- если нужен retry, публикует сообщение в retry queue;
- если лимит попыток исчерпан, публикует в DLQ.

Этот класс можно считать тонким orchestration-слоем.

### NotificationSendHandler

Главная бизнес-логика отправки notification.

Он:

- берет lock на `notification_id`;
- читает notification из БД;
- если notification уже не `queued`, возвращает `Ack`;
- выбирает provider по каналу;
- отправляет сообщение provider-у;
- переводит notification в `sent`;
- при permanent error переводит в `dropped`;
- при temporary error возвращает результат `Retry`;
- после лимита попыток переводит в `dropped` и возвращает `DeadLetter`.

Основная идея: повторная доставка RabbitMQ message не должна повторно отправлять notification provider-у, если статус уже изменился.

### NotificationProviderRegistry

Абстракция выбора provider client по каналу.

```text
sms   -> SMS provider client
email -> Email provider client
```

Handler не знает, какой конкретный класс и URL используются. Он знает только интерфейс `NotificationProviderClient`.

### HttpNotificationProviderClient

Инфраструктурный HTTP adapter к mock provider.

Он:

- отправляет `POST /api/v1/messages`;
- передает `X-Request-Id`;
- передает `Idempotency-Key = notification_id`;
- маппит HTTP ошибки в `TemporaryProviderException` или `PermanentProviderException`.

### DeliveryMessagePublisher

Интерфейс публикации технических сообщений в retry/DLQ.

Реализация `RabbitMqDeliveryMessagePublisher` знает:

- exchange retry;
- routing key retry;
- exchange DLQ;
- persistent AMQP message properties.

Application handler этого не знает.

### ProviderDeliveryStatusUpdater

Обрабатывает callback от provider:

```http
POST /api/providers/{provider}/webhooks
```

Он:

- маппит provider status во внутренний status;
- берет notification lock;
- применяет только монотонные переходы;
- пишет новую запись в `notification_status_history`.

Монотонность означает, что финальные статусы не откатываются:

```text
queued -> sent -> delivered
queued -> sent -> dropped
delivered -> sent     запрещено
dropped -> delivered  запрещено
```

## Почему это может казаться сложным

В маленьком проекте классов получается много:

```text
Command
Processor
Handler
Registry
Factory
Client
Publisher
Mapper
DTO
```

Это нормальная цена за явные границы. Но если проект небольшой, часть классов можно объединить.

## Более простая схема

Для этого проекта можно было бы сделать проще:

```text
ConsumeNotificationMessagesCommand
  -> NotificationSendHandler
      -> NotificationProviderFactory
      -> HttpNotificationProviderClient
      -> RabbitMqRetryPublisher
```

То есть убрать отдельные:

- `NotificationDeliveryProcessor`;
- `NotificationProviderRegistry`;
- `ProviderStatusMapper`.

И оставить:

- один handler для отправки;
- один factory для выбора provider;
- один HTTP client;
- один retry/DLQ publisher;
- отдельный updater для provider webhook.

## Когда нужен более сложный вариант

Текущий вариант оправдан, если ожидаются:

- несколько SMS providers;
- fallback между providers;
- разные retry policies по каналам;
- разные типы delivery workers;
- unit-тесты без RabbitMQ и HTTP;
- замена RabbitMQ на другой broker;
- расширение каналов, например push или messenger.

## Когда лучше упростить

Упростить стоит, если:

- есть только mock providers;
- каналов только два;
- retry policy одинаковая;
- проект учебный или тестовый;
- важнее читаемость, чем расширяемость.

Главное правило: сначала код должен быть понятен команде, и только потом готов к абстрактному будущему расширению.
