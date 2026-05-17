# Версии стека

В проекте запрещено использовать floating tags вроде `latest`. Все runtime, сервисы инфраструктуры и основные framework-пакеты фиксируются конкретными версиями.

| Компонент                  | Версия             | Назначение                                                                |
|----------------------------|--------------------|---------------------------------------------------------------------------|
| PHP                        | `8.5.6`            | Runtime Laravel Octane приложения.                                        |
| Laravel Framework          | `13.9.0`           | HTTP API Notification Service.                                            |
| Laravel Octane             | `2.17.3`           | Запуск Laravel под Swoole.                                                |
| Swoole                     | `6.2.1`            | Application server для Octane, установлен в готовом base image.           |
| PHP Redis extension        | `6.3.0`            | Клиент Redis для Laravel cache/session/metrics.                           |
| PHP/Swoole Docker base     | `phpswoole/swoole:php8.5-alpine@sha256:8ad6f59700f161f624a652eea924e0a51c1b8c5549c75e120325cbfde754998a` | Базовый образ Laravel Octane runtime с предустановленным Swoole. |
| PostgreSQL                 | `17.5`             | Основное хранилище batches, notifications, history, idempotency и outbox. |
| Redis                      | `7.4.2`            | Кэш, короткоживущие locks и вспомогательная координация.                  |
| RabbitMQ                   | `4.1.1-management` | Durable broker для send/retry/DLQ очередей.                               |
| VictoriaMetrics            | `1.102.1`          | Хранилище метрик.                                                         |
| Grafana                    | `11.5.2`           | Дашборды наблюдаемости.                                                   |
| Go                         | `1.26.3`           | Runtime mock SMS/Email provider servers.                                  |
| Docker Compose file format | `compose-spec`     | Запуск всего окружения одной командой.                                    |

Эти версии являются базовой матрицей разработки. Если при реализации потребуется обновление patch/minor версии из-за совместимости образов или пакетов, изменение должно быть явным: обновить этот файл, `.env.example`, Dockerfile/compose и README.
