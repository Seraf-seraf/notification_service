COMPOSE_FILE ?= infra/docker-compose.yml
APP_SERVICE ?= servicenotification
WORKER_SERVICE ?= worker

.PHONY: up down test test-integration migrate logs lint swagger-validate ps

up:
	docker compose -f $(COMPOSE_FILE) up --build

down:
	docker compose -f $(COMPOSE_FILE) down --remove-orphans

test:
	docker compose -f $(COMPOSE_FILE) run --rm $(APP_SERVICE) php artisan test --testsuite=Unit,Feature

test-integration:
	docker compose -f $(COMPOSE_FILE) run --rm $(APP_SERVICE) php artisan test --testsuite=Integration

migrate:
	docker compose -f $(COMPOSE_FILE) run --rm $(APP_SERVICE) php artisan migrate

logs:
	docker compose -f $(COMPOSE_FILE) logs -f --tail=200

lint:
	docker compose -f $(COMPOSE_FILE) run --rm $(APP_SERVICE) composer lint

swagger-validate:
	docker run --rm -v "$$(pwd):/work" -w /work redocly/cli:1.34.3 lint docs/openapi.yaml

ps:
	docker compose -f $(COMPOSE_FILE) ps
