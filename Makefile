.DEFAULT_GOAL := help

# GitHub Actions sets CI=true; there is no TTY there, so exec needs -T.
EXEC := docker compose exec $(if $(CI),-T,) php
CONSOLE := $(EXEC) php bin/console
NODE := docker compose --profile tools run --rm $(if $(CI),-T,) node

.PHONY: help build up down logs init sh vendor sf cache-clear \
	db-create db-migrate db-test db-fresh seed test test-short perf-check \
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
	$(MAKE) admin-install admin-build
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
test: sass db-test admin-build ## Run all test suites (PHPUnit, then Vitest)
	$(EXEC) php vendor/bin/phpunit
	$(MAKE) admin-test

test-short: sass admin-build ## Run PHPUnit, stop on first failure
	$(EXEC) php vendor/bin/phpunit --stop-on-defect

perf-check: sass db-test admin-build ## Byte budgets (S11): CSS, JS, HTML, fonts, admin
	$(EXEC) php vendor/bin/phpunit --group budget

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

lint: cs-check phpstan lint-translations admin-lint ## Run all static checks (the CI gate)

audit: ## Check dependencies for security advisories
	$(EXEC) composer audit

## —— Admin UI ——
.PHONY: admin-install admin-build admin-watch admin-test admin-lint
admin-install: ## Install the admin UI's npm packages (node tools service)
	$(NODE) npm ci

admin-build: ## Build the Vue islands into public/admin-build/
	$(NODE) npm run build

admin-watch: ## Rebuild the Vue islands on change
	$(NODE) npm run watch

admin-test: ## Run the Vitest suite
	$(NODE) npm test

admin-lint: ## Run ESLint on admin-ui/
	$(NODE) npm run lint

## —— Release and deploy ——
.PHONY: release-check deploy-test workflow-lint
ACTIONLINT_IMAGE := rhysd/actionlint:1.7.12

release-check: ## Build the release tarball and boot it with APP_ENV=prod (CI job "release")
	sh bin/build-release
	sh tests/Deploy/release-check.sh

deploy-test: ## Run the deploy, cron and import scripts against a throwaway tree, and the .htaccess against Apache
	docker compose exec -T php sh tests/Deploy/run.sh
	sh tests/Deploy/htaccess-check.sh

workflow-lint: ## actionlint on .github/workflows
	docker run --rm -v "$(CURDIR):/repo" -w /repo $(ACTIONLINT_IMAGE)

## —— Quality tooling (UI) ——
.PHONY: lighthouse validate-html
# URL is a path on this lane's app, e.g. URL=/_styleguide. _profile=0 keeps the dev toolbar (inline scripts) out.
TOOL_URL = http://localhost$(URL)$(if $(findstring ?,$(URL)),&,?)_profile=0
TOOL_NAME = $(shell date +%Y%m%d-%H%M%S)-$(or $(subst .,-,$(subst /,-,$(patsubst /%,%,$(firstword $(subst ?, ,$(URL)))))),home)
# ADMIN=1 or PORTAL=1 logs in through that firewall's DevAutoLoginAuthenticator (dev only).
ADMIN_DEV_TOKEN = $(or $(shell sed -n 's/^ADMIN_DEV_AUTOLOGIN=//p' .env.local 2>/dev/null | tail -n1),$(shell sed -n 's/^ADMIN_DEV_AUTOLOGIN=//p' .env | tail -n1))
DEV_LOGIN_HEADER = $(if $(PORTAL),X-Portal-Dev-Login,$(if $(ADMIN),X-Admin-Dev-Login))

lighthouse: ## Lighthouse (mobile) against URL=/path (ADMIN=1 or PORTAL=1 logs in); reports in var/lighthouse/
	@test -n "$(URL)" || { echo 'Usage: make lighthouse URL=/_styleguide [ADMIN=1|PORTAL=1]'; exit 1; }
	@mkdir -p var/lighthouse
	docker compose --profile tools run --rm lighthouse '$(TOOL_URL)' '$(TOOL_NAME)'$(if $(DEV_LOGIN_HEADER), '{"$(DEV_LOGIN_HEADER)":"$(ADMIN_DEV_TOKEN)"}')

# vnu refuses to fetch localhost URLs, so the php container saves the page (via nginx) and vnu reads the file.
validate-html: ## Nu Html Checker on the rendered HTML of URL=/path (ADMIN=1 or PORTAL=1 logs in)
	@test -n "$(URL)" || { echo 'Usage: make validate-html URL=/_styleguide [ADMIN=1|PORTAL=1]'; exit 1; }
	@mkdir -p var/validate
	$(EXEC) curl -fsS $(if $(DEV_LOGIN_HEADER),-H '$(DEV_LOGIN_HEADER): $(ADMIN_DEV_TOKEN)') '$(subst localhost,nginx,$(TOOL_URL))' -o var/validate/page.html
	docker compose --profile tools run --rm vnu --format text /work/page.html

## —— Performance (perf stack, PERF-1, see docs/performance-baseline.md) ——
.PHONY: perf-up perf-seed perf-run perf-lighthouse perf-down
# The explicit -f keeps the lane's compose.override.yaml (ports, subnet) out of the perf project.
PERF := NGINX_HOST_PORT=8096 DB_HOST_PORT=5446 docker compose -p deine-eventlocation-perf -f compose.yaml -f compose.perf.yaml
PERF_PHP := $(PERF) exec -T php
PERF_DB := $(PERF) exec -T database psql -U app -v ON_ERROR_STOP=1
TIMES ?= 1
MODE ?= uncached
PERF_STAMP := $(shell date +%Y%m%d-%H%M%S)
PERF_LH_NAME = $(or $(NAME),$(shell printf '%s' '$(URL)' | tr -c 'a-zA-Z0-9' '-' | sed 's/--*/-/g;s/^-//;s/-$$//'))

# --no-scripts keeps importmap:install from writing to the worktree's assets/vendor/; the next steps
# do the scripts' work. sass:build runs before asset-map:compile, which copies its CSS. The restart
# empties OPcache, which no longer checks file timestamps: rerun perf-up after every code change.
perf-up: ## Start the prod-mode perf stack on :8096 and prepare it (rerun after code changes)
	$(PERF) up -d --wait php nginx database
	$(PERF_PHP) composer install --no-dev --classmap-authoritative --no-scripts --no-interaction
	$(PERF_PHP) php bin/console cache:clear
	$(PERF_PHP) php bin/console app:http-cache:clear
	$(PERF_PHP) php bin/console sass:build
	$(PERF_PHP) php bin/console asset-map:compile
	$(PERF_PHP) chown -R www-data:www-data var
	$(PERF) restart php nginx

# A pg_dump of this lane's database, so photo ids match public/media; the lane must be freshly seeded
# (make db-fresh && make seed). tests/Load/live-canton.sql then moves 2 venues to Zürich, so the
# canton page is live at prod's threshold of 5; TIMES=10 clones every venue 9 times (times-ten.sql).
# cache.global_clearer empties the filesystem pools (the footer's cantons of #17), which outlive the
# restart, and app:http-cache:clear the store, which neither reaches: entries of the previous dataset
# or code would stay until midnight. The restart empties OPcache. Both targets run the console as root, and fpm's www-data must
# write var/share: HttpCache's store answers every URL with a 500 when it cannot create its directory.
perf-seed: ## Copy the lane's seeded database into the perf stack (TIMES=10 for demo data ×10)
	$(PERF_DB) -d postgres -c 'DROP DATABASE IF EXISTS app WITH (FORCE)'
	$(PERF_DB) -d postgres -c 'CREATE DATABASE app'
	docker compose exec -T database pg_dump -U app --no-owner app | $(PERF_DB) -q -d app > /dev/null
	$(PERF_DB) -d app -c 'CREATE EXTENSION IF NOT EXISTS pg_stat_statements'
	$(PERF_DB) -d app < tests/Load/live-canton.sql
	$(if $(filter 10,$(TIMES)),$(PERF_DB) -d app < tests/Load/times-ten.sql)
	@mkdir -p var/perf && echo '$(TIMES)' > var/perf/seed-times
	$(PERF_PHP) php bin/console cache:pool:clear cache.global_clearer --no-interaction
	$(PERF_PHP) php bin/console app:http-cache:clear
	$(PERF_PHP) chown -R www-data:www-data var
	$(PERF) restart php

perf-run: ## k6 against the perf stack: MODE=uncached|all; results in var/perf/
	tests/Load/run.sh $(MODE)

# Warms the URL twice, then 3 Lighthouse runs through the perf nginx (network_mode service:nginx).
# The store key holds the host, so the warm-up must send Lighthouse's: every run is then a hit.
perf-lighthouse: ## Lighthouse (mobile) 3 times against the perf stack's URL=/path [NAME=…]; reports in var/lighthouse/
	@test -n "$(URL)" || { echo 'Usage: make perf-lighthouse URL=/hochzeitslocations [NAME=listing]'; exit 1; }
	@mkdir -p var/lighthouse
	$(PERF_PHP) curl -s -o /dev/null -H 'Host: localhost' 'http://nginx$(URL)'
	$(PERF_PHP) curl -s -o /dev/null -H 'Host: localhost' 'http://nginx$(URL)'
	for i in 1 2 3; do $(PERF) --profile tools run --rm lighthouse 'http://localhost$(URL)' "perf-$(PERF_STAMP)-$(PERF_LH_NAME)-$$i" || exit 1; done

perf-down: ## Stop the perf stack and delete its volumes
	$(PERF) down -v
