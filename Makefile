COMPOSE_FILE ?= infra/docker-compose.yml
APP_SERVICE ?= servicenotification
WORKER_SERVICE ?= worker
TEST_ENV ?= -e APP_ENV=testing -e DB_DATABASE=notification_service_test -e CACHE_STORE=array -e SESSION_DRIVER=array

.PHONY: up down test migrate logs ps

up:
	docker compose -f $(COMPOSE_FILE) up --build --remove-orphans

down:
	docker compose -f $(COMPOSE_FILE) down --remove-orphans

test:
	docker compose -f $(COMPOSE_FILE) run --rm $(TEST_ENV) $(APP_SERVICE) php artisan test --testsuite=Unit,Feature

migrate:
	docker compose -f $(COMPOSE_FILE) run --rm $(APP_SERVICE) php artisan migrate --force

logs:
	docker compose -f $(COMPOSE_FILE) logs -f --tail=200

ps:
	docker compose -f $(COMPOSE_FILE) ps
