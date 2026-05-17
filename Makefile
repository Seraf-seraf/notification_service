COMPOSE_FILE ?= infra/docker-compose.yml
APP_SERVICE ?= servicenotification
WORKER_SERVICE ?= worker
TEST_ENV ?= -e APP_ENV=testing -e DB_DATABASE=notification_service_test -e CACHE_STORE=array -e SESSION_DRIVER=array
APP_CODE_MOUNTS ?= -v "$$(pwd)/apps/servicenotification/app:/app/app" -v "$$(pwd)/apps/servicenotification/bootstrap:/app/bootstrap" -v "$$(pwd)/apps/servicenotification/config:/app/config" -v "$$(pwd)/apps/servicenotification/database:/app/database" -v "$$(pwd)/apps/servicenotification/routes:/app/routes" -v "$$(pwd)/apps/servicenotification/tests:/app/tests"
OPENAPI_IMAGE ?= redocly/cli:1.34.5

.PHONY: up down test test-integration test-e2e migrate logs ps lint format swagger-validate compose-config final-check

up:
	docker compose -f $(COMPOSE_FILE) up --build --remove-orphans

down:
	docker compose -f $(COMPOSE_FILE) down --remove-orphans

test:
	docker compose -f $(COMPOSE_FILE) run --rm $(APP_CODE_MOUNTS) $(TEST_ENV) $(APP_SERVICE) php artisan test --testsuite=Unit,Feature

test-integration:
	docker compose -f $(COMPOSE_FILE) run --rm $(APP_CODE_MOUNTS) $(TEST_ENV) $(APP_SERVICE) php artisan test tests/Feature/Outbox tests/Feature/Worker

test-e2e:
	docker compose -f $(COMPOSE_FILE) run --rm $(APP_CODE_MOUNTS) $(TEST_ENV) $(APP_SERVICE) php artisan test --group=e2e

migrate:
	docker compose -f $(COMPOSE_FILE) run --rm $(APP_SERVICE) php artisan migrate --force

logs:
	docker compose -f $(COMPOSE_FILE) logs -f --tail=200

ps:
	docker compose -f $(COMPOSE_FILE) ps

lint:
	docker compose -f $(COMPOSE_FILE) run --rm $(APP_CODE_MOUNTS) $(APP_SERVICE) composer lint
	docker run --rm -v "$$(pwd)/apps/smsprovider:/src" -w /src golang:1.26.3-alpine3.22 go test ./...
	docker run --rm -v "$$(pwd)/apps/emailprovider:/src" -w /src golang:1.26.3-alpine3.22 go test ./...

format:
	docker compose -f $(COMPOSE_FILE) run --rm $(APP_CODE_MOUNTS) $(APP_SERVICE) composer format

swagger-validate:
	docker run --rm -v "$$(pwd):/work" -w /work $(OPENAPI_IMAGE) lint docs/openapi.yaml

compose-config:
	docker compose -f $(COMPOSE_FILE) config --quiet

final-check: compose-config lint test test-integration test-e2e swagger-validate
