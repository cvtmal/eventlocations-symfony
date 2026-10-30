.DEFAULT_GOAL := help

# GitHub Actions sets CI=true; there is no TTY there, so exec needs -T.
EXEC := docker compose exec $(if $(CI),-T,) php
CONSOLE := $(EXEC) php bin/console

.PHONY: help build up down logs init sh vendor sf cache-clear \
	db-create db-migrate db-test db-fresh seed test test-short \
	cs-check cs-fix phpstan lint audit

help: ## List available targets
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

## —— Lifecycle ——
build: ## Build the PHP image
	docker compose build

up: ## Start the stack in the background
	docker compose up -d

down: ## Stop the stack
	docker compose down

logs: ## Follow logs of all services
	docker compose logs -f

init: ## First run: build, install vendors, create and migrate the database, start everything
	docker compose up -d --build --wait php database
	$(MAKE) vendor
	$(MAKE) sass
	$(MAKE) db-create
	$(MAKE) db-migrate
	docker compose up -d

## —— Shell & console ——
sh: ## Open a shell in the php container
	docker compose exec php bash

vendor: ## Install Composer dependencies
	$(EXEC) composer install --no-interaction

sf: ## Run a console command, e.g. make sf c="debug:router"
	$(CONSOLE) $(c)

cache-clear: ## Clear the Symfony cache
	$(CONSOLE) cache:clear

## —— Assets ——
.PHONY: sass sass-watch
sass: ## Build the Sass entry points into var/sass/ (needed before rendering any page)
	$(CONSOLE) sass:build
sass-watch: ## Rebuild Sass on change
	$(CONSOLE) sass:build --watch

## —— Database ——
db-create: ## Create the database if missing
	$(CONSOLE) doctrine:database:create --if-not-exists

db-migrate: ## Run migrations
	$(CONSOLE) doctrine:migrations:migrate --no-interaction --allow-no-migration

db-test: ## Create and migrate the test database
	$(CONSOLE) doctrine:database:create --if-not-exists --env=test
	$(CONSOLE) doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test

# php-fpm's persistent connections keep sessions open on `app`, which a
# plain DROP DATABASE rejects. FORCE terminates them; the restart replaces the dead ones.
db-fresh: ## Drop, recreate and migrate the database
	docker compose exec $(if $(CI),-T,) database psql -U app -d postgres -c 'DROP DATABASE IF EXISTS app WITH (FORCE)'
	$(MAKE) db-create
	$(MAKE) db-migrate
	docker compose restart php

seed: ## Import towns and load the demo data (Foundry story "main") into the dev database
	$(CONSOLE) app:geo:import-towns --no-interaction
	$(CONSOLE) foundry:load-fixtures main --append --no-interaction

## —— Tests ——
test: sass db-test ## Run all test suites
	$(EXEC) php vendor/bin/phpunit

test-short: sass ## Run tests, stop on first failure
	$(EXEC) php vendor/bin/phpunit --stop-on-defect

## —— Quality ——
cs-check: ## Check coding standards
	$(EXEC) php vendor/bin/php-cs-fixer check --diff

cs-fix: ## Fix coding standards
	$(EXEC) php vendor/bin/php-cs-fixer fix

phpstan: ## Run PHPStan (level 8 + architecture rules)
	$(EXEC) php vendor/bin/phpstan analyse --memory-limit=1G --no-progress

.PHONY: lint-translations
lint-translations: ## Check ICU syntax of every catalogue
	$(CONSOLE) lint:translations

lint: cs-check phpstan lint-translations ## Run all static checks (the CI gate)

audit: ## Check dependencies for security advisories
	$(EXEC) composer audit

## —— Quality tooling (UI) ——
.PHONY: lighthouse validate-html
# URL is a path on this lane's app, e.g. URL=/_styleguide. _profile=0 keeps the dev toolbar (inline scripts) out.
TOOL_URL = http://localhost$(URL)$(if $(findstring ?,$(URL)),&,?)_profile=0
TOOL_NAME = $(shell date +%Y%m%d-%H%M%S)-$(or $(subst /,-,$(patsubst /%,%,$(firstword $(subst ?, ,$(URL))))),home)

lighthouse: ## Lighthouse (mobile) against URL=/path; reports in var/lighthouse/
	@test -n "$(URL)" || { echo 'Usage: make lighthouse URL=/_styleguide'; exit 1; }
	@mkdir -p var/lighthouse
	docker compose --profile tools run --rm lighthouse '$(TOOL_URL)' '$(TOOL_NAME)'

# vnu refuses to fetch localhost URLs, so the php container saves the page (via nginx) and vnu reads the file.
validate-html: ## Nu Html Checker on the rendered HTML of URL=/path
	@test -n "$(URL)" || { echo 'Usage: make validate-html URL=/_styleguide'; exit 1; }
	@mkdir -p var/validate
	$(EXEC) curl -fsS '$(subst localhost,nginx,$(TOOL_URL))' -o var/validate/page.html
	docker compose --profile tools run --rm vnu --format text /work/page.html
