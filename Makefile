COMPOSE_FILE ?= infra/docker-compose.yml
COMPOSE_DEV_FILE ?= infra/docker-compose.dev.yml
COMPOSE ?= docker compose -f $(COMPOSE_FILE) -f $(COMPOSE_DEV_FILE)
APP_SERVICE ?= servicenotification
TEST_ENV ?= -e APP_ENV=testing -e DB_DATABASE=notification_service_test -e CACHE_STORE=array -e SESSION_DRIVER=array
OPENAPI_IMAGE ?= redocly/cli:1.34.5

.PHONY: up down test migrate logs ps swagger-validate

up:
	$(COMPOSE) up -d --build --remove-orphans

down:
	$(COMPOSE) down --remove-orphans

test:
	$(COMPOSE) run --rm $(TEST_ENV) $(APP_SERVICE) php artisan test --testsuite=Unit,Feature

migrate:
	$(COMPOSE) run --rm $(APP_SERVICE) php artisan migrate --force

logs:
	$(COMPOSE) logs -f --tail=200

ps:
	$(COMPOSE) ps

swagger-validate:
	docker run --rm -v "$$(pwd):/work" -w /work $(OPENAPI_IMAGE) lint docs/openapi.yaml
