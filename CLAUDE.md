# Hochzeitslocation

Swiss wedding-venue listing site: a home page, one listing page with filters, one detail page per venue, and a Merkliste without login. It is modelled on eventlokale.ch's wedding search. Scope, settled decisions, requirements and the cycle order live in `docs/rpi-cycles.md`; read it before starting a cycle.

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
| `make test` | Prepare `app_test`, build Sass and the islands, run PHPUnit, then Vitest |
| `make test-short` | Run PHPUnit, stop at the first failure |
| `make cs-fix` / `make cs-check` | Fix / check coding standards |
| `make phpstan` | PHPStan level 8 + architecture rules |
| `make lint` | `cs-check` + `phpstan` + `lint-translations` + `admin-lint` (the CI gate) |
| `make admin-install` | `npm ci` for `admin-ui/` (in the `node` tools service) |
| `make admin-build` / `make admin-watch` | Build the Vue islands into `public/admin-build/` / rebuild on change. Every `/admin` page reads the Vite manifest, so a fresh worktree or a checkout after a pull needs it (with `make admin-install`); `make init` does it |
| `make admin-test` / `make admin-lint` | Vitest / ESLint on `admin-ui/` |
| `make lighthouse URL=/path ADMIN=1` / `make validate-html URL=/path ADMIN=1` | The same tools logged in as the dev admin (dev only) |
| `make lighthouse URL=/konto PORTAL=1` / `make validate-html URL=/konto PORTAL=1` | The same tools logged in as the dev manager (dev only, after `make seed`) |
| `make perf-check` | Byte budgets of S11 through `phpunit --group budget` (CSS, JS, HTML, fonts, admin); needs no perf stack, runs in CI |
| `make perf-up` / `make perf-seed [TIMES=10]` / `make perf-run MODE=uncached\|all` / `make perf-lighthouse URL=/path` / `make perf-down` | Prod-mode perf stack on :8096: start and prepare it (rerun after a code change), copy the lane's seeded database (`TIMES=10` for k6, ×1 for Lighthouse), k6 into `var/perf/`, Lighthouse 3×, stop and delete its volumes. Method and figures: `docs/performance-baseline.md` |
| `make release-check` | Build the release tarball and boot it with `APP_ENV=prod` (CI job `release`) |
| `make deploy-test` | Run the deploy, cron and import scripts against a throwaway tree, and `public/.htaccess` against Apache |
| `make workflow-lint` | actionlint on `.github/workflows` |

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
- Never run two `phpunit` processes in one lane: `tests/bootstrap.php` deletes `var/cache/test` at start, which wipes the cache of a suite already running.

## Architecture

Module-first modular monolith: `src/<Module>/<Layer>/`, namespace `App\<Module>\<Layer>`.

- Modules: `Geo`, `Media`, `Venues`, `Search`, `Favorites`, `Inquiries`, `Seo`, the back office's `Admin`, `Cms`, `Crm`, the location managers' `Portal`, plus `Shared` for building blocks.
- Layers inside a module: `Controller`, `Command` (command DTOs), `CommandHandler`, `Entity`, `Repository`, `ReadModel` (DBAL queries returning `final readonly` view objects), `ValueObject`, `Exception`, `Form`, `Console` (console commands), `Twig` (Twig filters and functions), `Mailer` (a module's emails), `EventListener`, `Event` (a module's published events), `Extension` (back-office extension points and their implementations), `Security` (security user, provider, authenticators), `Messenger` (bus middleware).
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
  | Admin | — |
  | Cms | Admin, Venues, Geo, Media |
  | Crm | Admin, Venues, Geo, Inquiries, Portal |
  | Portal | Admin, Cms, Venues, Geo, Media |

  No public module depends on `Admin`, `Cms`, `Crm` or `Portal`.

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
- `/` is `Search\Controller\HomeController` (`home`), see `### Search`.
- `Mailer\EmailHtmlToTextConverter`: derives every mail's text part (`twig.mailer.html_to_text_converter`). `templates/emails/layout.html.twig` + `_blocks.html.twig` (macros `greeting`, `p`, `button`, `note`, `rule`, `facts`, `quote`, `rich`): the inline-styled mail layout, free of module wording, class prefix `hl-`. Built in #14, reused by #30.
- `Validator\CleanText` (#24): refuses a NUL and invalid UTF-8 (PDO truncates at a NUL, Postgres answers SQLSTATE 22021) without naming the value, because the `+intl-icu` formatter throws on invalid UTF-8 in a message parameter. `Admin\Form\AdminTextConstraintExtension` adds it to every back-office text field; a JSON DTO puts `#[CleanText]` on its string properties.

Languages, catalogues and routes: `.claude/rules/i18n.md`.

## Modules

Each module's notes live in `.claude/rules/module-<name>.md`, which load by themselves when a matching file is read. Read one by hand when the work crosses into it. Elsewhere in the repo, `CLAUDE.md` `### <Module>` and `see ### <Module>` mean that file. Each cycle appends to its module's file (hotspot rule, `docs/rpi-cycles.md` §Running cycles in parallel).

| Module | Notes | Holds | Also read it for |
|---|---|---|---|
| Shared | `.claude/rules/module-shared.md` | EntityId, translation, design system, security headers, content blocks, HTTP cache, deploy, cron, production settings | any new public route, cache header or nightly job |
| Geo | `.claude/rules/module-geo.md` | Cantons, towns, place lookup, distances, canton map |  |
| Media | `.claude/rules/module-media.md` | Image variants, `<picture>`, regeneration |  |
| Venues | `.claude/rules/module-venues.md` | Venue aggregate, detail page, imports, CMS write side | any venue write from Cms or Crm |
| Search | `.claude/rules/module-search.md` | `SearchCriteria`, home page, listing, filters, landing pages, similar venues | a change to `search/_card` |
| Favorites | `.claude/rules/module-favorites.md` | Merkliste cookie, toggle, sync, page |  |
| Inquiries | `.claude/rules/module-inquiries.md` | Inquiry form, mails, retention, events |  |
| Seo | `.claude/rules/module-seo.md` | Meta, JSON-LD, sitemap, robots, error pages | any page's `title`, `meta` or `canonical` block |
| Admin | `.claude/rules/module-admin.md` | Staff login, shell, extension points, audit, UI kit, Vue islands | any back-office work, Cms and Crm included |
| Cms | `.claude/rules/module-cms.md` | Back-office screens for venues, media, landing pages, text blocks |  |
| Crm | `.claude/rules/module-crm.md` | Companies, contacts, deals, tasks, activities |  |
| Portal | `.claude/rules/module-portal.md` | Manager accounts, `/konto` firewall and session cookie, signed links, venue access, staff account list | any `/konto` route or `security.yaml` edit |

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
