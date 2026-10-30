# Hochzeitslocation

Swiss wedding-venue listing site: one listing page with filters, one detail page per venue, and a Merkliste without login. It is modelled on eventlokale.ch's wedding search. Scope, settled decisions, requirements and the cycle order live in `docs/rpi-cycles.md`; read it before starting a cycle.

## Tech Stack

- Symfony 7.4 (LTS) on PHP 8.5, served by php-fpm behind nginx
- PostgreSQL 18 with `pg_trgm`, `unaccent`, `cube` and `earthdistance` (Neon in prod, same major locally and in CI)
- Doctrine ORM 3 / DBAL 4, hand-written SQL migrations
- Symfony Messenger with one synchronous `command.bus`
- Twig, Forms, AssetMapper; no Node, no JS framework (S1, S3)
- Docker Compose for everything local: `php`, `nginx`, `database`, `mailpit`

## Commands

Never run `composer` or `bin/console` on the host. The host has a different PHP version; every target below runs inside the `php` container.

| Command | What it does |
|---|---|
| `make init` | First run: build image, `composer install`, create + migrate DB, start the stack |
| `make up` / `make down` / `make logs` | Start, stop, follow logs |
| `make sh` | Shell in the php container |
| `make sf c="debug:router"` | Any console command |
| `make vendor` | `composer install` |
| `make db-migrate` / `make db-fresh` | Run migrations / drop, recreate and migrate |
| `make seed` | Load the demo data (Foundry story `main`) into the dev database |
| `make test` | Prepare `app_test`, run all suites |
| `make test-short` | Run tests, stop at the first failure |
| `make cs-fix` / `make cs-check` | Fix / check coding standards |
| `make phpstan` | PHPStan level 8 + architecture rules |
| `make lint` | `cs-check` + `phpstan` (the CI gate) |

App: http://localhost:8080 (`/health` returns `{"status":"ok"}`). Mail UI: http://localhost:8025.

## Testing

| Suite | Folder | Base class | Use for |
|---|---|---|---|
| Unit | `tests/Unit` | `PHPUnit\Framework\TestCase` | Pure PHP, no container |
| Integration | `tests/Integration` | `KernelTestCase` | Services, repositories, real Postgres |
| Application | `tests/Application` | `WebTestCase` | HTTP in, HTML/JSON out |
| Architecture | `tests/Architecture/*ProbeTest.php` | `PHPUnit\Framework\TestCase` | Proves the phpat rules fire |

- Test classes are `final`; methods are `test_snake_case` (enforced by PHP-CS-Fixer).
- `make test` creates and migrates the `app_test` database first.
- PHPUnit fails on deprecations, notices and warnings coming from `src/`.
- Factories use Foundry (`zenstruck/foundry`).
- The phpat rule classes in `tests/Architecture/` run inside `make phpstan`, not PHPUnit.

## Architecture

Module-first modular monolith: `src/<Module>/<Layer>/`, namespace `App\<Module>\<Layer>`.

- Modules: `Geo`, `Media`, `Venues`, `Search`, `Favorites`, `Inquiries`, `Seo`, plus `Shared` for building blocks.
- Layers inside a module: `Controller`, `Command` (command DTOs), `CommandHandler`, `Entity`, `Repository`, `ReadModel` (DBAL queries returning `final readonly` view objects), `ValueObject`, `Exception`, `Form`, `Console` (console commands), `Twig` (Twig filters and functions), `Mailer` (a module's emails), `EventListener`.
- Allowed module dependencies (everything may use `Shared`; `Shared` uses no module):

  | Module | May depend on |
  |---|---|
  | Geo | — |
  | Media | — |
  | Venues | Geo, Media |
  | Search | Venues, Geo, Media |
  | Favorites | Search, Venues |
  | Inquiries | Venues |
  | Seo | Search, Venues, Geo |

- Reads: public pages go controller → `ReadModel` (DBAL, `final readonly` view objects), never entities. Writes: controller or console command → command → `command.bus` → handler. There is no query bus.
- phpat enforces layers, modifiers and module boundaries during `make phpstan` (rules in `tests/Architecture/`). Details: `.claude/rules/php-architecture.md`, `.claude/rules/cqrs.md`, `.claude/rules/read-model.md`.

## Validation strategy

Three layers, each catching what the previous one cannot:

1. **Forms** validate input shape (Symfony Form + Validator on a form data class).
2. **Handlers** enforce business rules and throw `DomainException` subclasses.
3. **Database constraints** guarantee uniqueness and integrity under concurrency.

Details: `.claude/rules/validation.md`.

## Building blocks (`src/Shared`)

- `Uid\EntityId::generate()`: the only way to create an entity ID (UUID v7 string).
- `Doctrine\Type\FlexibleDateTimeTzImmutableType`: replaces `datetimetz_immutable` so Postgres values with microseconds hydrate.
- `Exception\DomainException`: abstract base for business errors.
- `Messenger\CommandHandlerInterface`: marker that registers a handler on `command.bus`.
- `Controller\HealthController`: `GET /health`, checks the database.
- `Controller\HomeController`: the placeholder `/` until #7 replaces it with the redirect to `/hochzeitslocations` (S5).

Languages, catalogues and routes: `.claude/rules/i18n.md`.

## Modules

Each cycle appends to its module's subsection (hotspot rule, `docs/rpi-cycles.md` §Running cycles in parallel).

### Shared

`EntityId`, `DomainException`, translation helpers, Swiss formatting Twig extension, HTTP cache helpers, legal pages, health.

Built in #0: the building blocks above, the placeholder `HomeController` (`home`, `/`), and the first migration with the Postgres extensions.

Built in #2: `Shared\Translation\{EnabledLocale, LocalizedRoutes, HreflangAlternates, TranslationJoin}`, `Shared\Twig\{SwissFormatExtension, HreflangExtension}` (`swiss_number`, `swiss_date`, `swiss_range`, `hreflang_alternates`), `templates/shared/_hreflang.html.twig`, catalogue stubs, and `make lint-translations`, which `make lint` now also runs. Rules: `.claude/rules/i18n.md`.

### Geo

Cantons, towns, postcode/town lookup, distances.

_Nothing built yet; the owning cycle appends here._

### Media

Image variant generation, `<picture>` Twig helper.

_Nothing built yet; the owning cycle appends here._

### Venues

Venue aggregate (translations, terms, rooms, photos, documents, reviews), detail page, import.

_Nothing built yet; the owning cycle appends here._

### Search

`SearchCriteria`, listing read model, counts, ordering, similar venues, autocomplete endpoints, listing page.

_Nothing built yet; the owning cycle appends here._

### Favorites

Merkliste cookie codec, toggle endpoint, `/merkliste` page.

_Nothing built yet; the owning cycle appends here._

### Inquiries

Inquiry form, entity, mails.

_Nothing built yet; the owning cycle appends here._

### Seo

Sitemap, robots, JSON-LD builders, landing page definitions.

_Nothing built yet; the owning cycle appends here._

## Exception conventions

- Every business error is a `final` class in `<Module>\Exception` extending `App\Shared\Exception\DomainException`.
- Create them through named constructors (`VenueNotFound::forSlug($slug)`), not `new` with a message at the call site.
- Infrastructure errors (DB down, HTTP failures) are not domain exceptions; let them bubble.

## Database & migrations

Hand-written SQL migrations only; never `doctrine:migrations:diff`. Every migration has a `down()`. Name each migration `VersionYYYYMMDDHHMMSS` from the wall clock at the moment it is written; the Schema ownership column of the overview in `docs/rpi-cycles.md` decides which cycle may alter a table. Naming, types and entity mapping rules: `.claude/rules/database.md`.

## Local ports and parallel lanes

Host ports default to 8080 (nginx), 5432 (Postgres) and 8025 (Mailpit), the main checkout's lane. Each cycle runs in its own git worktree, and each worktree is its own Compose project (own containers, volume and `app_test`). Set the lane's ports per shell:

```sh
export NGINX_HOST_PORT=8081 DB_HOST_PORT=5433 MAILPIT_HOST_PORT=8026
```

or in a git-ignored `compose.override.yaml`. The lane table is in `docs/rpi-cycles.md` §Running cycles in parallel.

## CI

`.github/workflows/ci.yaml` starts the same Compose `php` + `database` services and runs `make vendor`, `make lint`, `make test`. `make lint` passing locally means CI lint passes. Pre-commit (`.pre-commit-config.yaml`) runs `make cs-fix` and `make phpstan`; activate with `brew install pre-commit && pre-commit install`.

## Order of work

`make cs-fix` → `make lint` → `make test`. Every UI cycle also runs the checks in S11 of `docs/rpi-cycles.md`. See `.claude/rules/code-quality.md`.
