.DEFAULT_GOAL := help
DC   := docker compose
EXEC := $(DC) exec app
CONSOLE := $(EXEC) php bin/console

.PHONY: help start stop restart build logs sh install migrate diff fixtures jwt cc test cs cs-fix stan reset-db

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

start: build ## Build, start the stack, install deps, migrate
	$(DC) up -d --wait database redis
	$(DC) up -d
	$(MAKE) install
	$(MAKE) jwt
	$(MAKE) migrate
	@echo "API: http://localhost:8080/api  |  Mercure: http://localhost:8081/.well-known/mercure"

stop: ## Stop the stack
	$(DC) down

restart: stop start ## Restart the stack

build: ## Build images
	$(DC) build

logs: ## Tail logs
	$(DC) logs -f --tail=100

sh: ## Shell in the app container
	$(EXEC) sh

install: ## Install Composer dependencies (skipped if vendor/ exists)
	@test -d vendor/symfony || $(EXEC) composer install --no-interaction

migrate: ## Run migrations
	$(CONSOLE) doctrine:migrations:migrate --no-interaction

diff: ## Generate a migration from entity changes
	$(CONSOLE) doctrine:migrations:diff

fixtures: ## Load fixtures (purges the database)
	$(CONSOLE) doctrine:fixtures:load --no-interaction

jwt: ## Generate the JWT keypair if missing
	$(CONSOLE) lexik:jwt:generate-keypair --skip-if-exists

cc: ## Clear cache
	$(CONSOLE) cache:clear

test: ## Run PHPUnit (rebuilds the test database)
	$(DC) exec -e APP_ENV=test app php bin/console doctrine:database:create --if-not-exists --no-interaction
	$(DC) exec -e APP_ENV=test app php bin/console doctrine:schema:drop --force --full-database --no-interaction
	$(DC) exec -e APP_ENV=test app php bin/console doctrine:schema:create --no-interaction
	$(DC) exec -e APP_ENV=test app vendor/bin/phpunit

cs: ## Check code style (PSR-12)
	$(EXEC) vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix: ## Fix code style
	$(EXEC) vendor/bin/php-cs-fixer fix

stan: ## Run PHPStan
	$(EXEC) vendor/bin/phpstan analyse

reset-db: ## Drop, recreate, migrate and load fixtures
	$(CONSOLE) doctrine:database:drop --force --if-exists
	$(CONSOLE) doctrine:database:create
	$(MAKE) migrate
	$(MAKE) fixtures

# ---------------------------------------------------------------- production
PROD := docker compose -f compose.prod.yaml --env-file .env.prod

.PHONY: secrets prod-build prod-deploy prod-migrate prod-up prod-down prod-logs prod-ps prod-console prod-check cert cert-renew backup restore verify-backup

secrets: ## Create .env.prod with fresh random secrets (never overwrites an existing file)
	@./scripts/make-env-prod.sh

prod-build: ## Build the production images (the current ones are kept as :previous for a rollback)
	-@docker tag rasoherina-app:prod rasoherina-app:previous 2>/dev/null
	-@docker tag rasoherina-nginx:prod rasoherina-nginx:previous 2>/dev/null
	$(PROD) build

prod-check: ## Validate .env.prod and the compose file
	@$(PROD) config -q && echo "compose.prod.yaml: OK"
	@$(PROD) run --rm --no-deps -e APP_ENV=prod app php bin/console app:check-production

prod-migrate: ## Run database migrations (one-shot)
	$(PROD) up -d --wait database redis
	$(PROD) run --rm migrate

prod-deploy: prod-build prod-migrate ## Build, migrate and (re)start everything
	$(PROD) up -d --remove-orphans
	$(PROD) ps

prod-up: ## Start the production stack
	$(PROD) up -d

prod-down: ## Stop the production stack (volumes are kept)
	$(PROD) down

prod-logs: ## Tail production logs
	$(PROD) logs -f --tail=100

prod-ps: ## Production services status
	$(PROD) ps

prod-console: ## Run a console command: make prod-console CMD="app:reports"
	$(PROD) exec app php bin/console $(CMD)

cert: ## First TLS certificate (Let's Encrypt, port 80 must be free)
	@./scripts/certbot.sh issue

cert-renew: ## Renew certificates and reload nginx (run from cron)
	@./scripts/certbot.sh renew

backup: ## Dump the database and the uploads into $(BACKUP_DIR)
	@COMPOSE="$(PROD)" ./scripts/backup.sh

verify-backup: ## Restore a dump into a scratch database to prove it is usable: make verify-backup FILE=backups/db-….dump
	@COMPOSE="$(PROD)" ./scripts/backup.sh verify $(FILE)

restore: ## Restore a dump: make restore FILE=backups/db-….dump  (or uploads-….tar.gz)
	@COMPOSE="$(PROD)" ./scripts/restore.sh $(FILE)
