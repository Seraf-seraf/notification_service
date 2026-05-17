ARG SWOOLE_BASE_IMAGE=phpswoole/swoole:php8.5-alpine@sha256:8ad6f59700f161f624a652eea924e0a51c1b8c5549c75e120325cbfde754998a

FROM ${SWOOLE_BASE_IMAGE} AS base

WORKDIR /app

RUN apk add --no-cache \
        bash \
        ca-certificates \
        curl \
        libpq \
        tzdata \
        unzip \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        postgresql-dev \
    && docker-php-ext-install \
        pcntl \
        pdo_pgsql \
    && apk del .build-deps

COPY --from=composer:2.8.10 /usr/bin/composer /usr/bin/composer

FROM base AS development

COPY composer.json composer.lock ./
RUN composer install \
        --prefer-dist \
        --no-interaction \
        --no-progress \
        --optimize-autoloader \
        --no-scripts

COPY . .
RUN cp .env.example .env \
    && touch .phpunit.result.cache \
    && composer dump-autoload --optimize \
    && php artisan package:discover --ansi \
    && mkdir -p storage/app storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && addgroup -g 10001 app \
    && adduser -D -H -u 10001 -G app app \
    && chown -R app:app .env .phpunit.result.cache storage bootstrap/cache

USER app

EXPOSE 8080

CMD ["sh", "-c", "php artisan octane:start --server=${OCTANE_SERVER:-swoole} --host=${OCTANE_HOST:-0.0.0.0} --port=${OCTANE_PORT:-8080} --workers=${OCTANE_WORKERS:-4} --max-requests=${OCTANE_MAX_REQUESTS:-500}"]

FROM base AS production

ENV APP_ENV=production \
    APP_DEBUG=false

COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --prefer-dist \
        --no-interaction \
        --no-progress \
        --optimize-autoloader \
        --no-scripts

COPY . .
RUN composer dump-autoload --no-dev --optimize \
    && php artisan package:discover --ansi \
    && mkdir -p storage/app storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && addgroup -g 10001 app \
    && adduser -D -H -u 10001 -G app app \
    && chown -R app:app storage bootstrap/cache

USER app

EXPOSE 8080

CMD ["sh", "-c", "php artisan octane:start --server=${OCTANE_SERVER:-swoole} --host=${OCTANE_HOST:-0.0.0.0} --port=${OCTANE_PORT:-8080} --workers=${OCTANE_WORKERS:-4} --max-requests=${OCTANE_MAX_REQUESTS:-500}"]
