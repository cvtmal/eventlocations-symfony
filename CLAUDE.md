# deine-eventlocation.ch

Swiss listing of event venues (company events, seminars, birthdays, apéros, Christmas parties, general meetings, weddings): a home page, a listing with filters, a detail page per venue, a Merkliste without login, an inquiry form, a back office at `/admin` (CMS, CRM, statistics) and an area for venue managers at `/konto`. German only, "du" everywhere. Modelled on eventlokale.ch's features and behaviour; its texts, photos, venue data and branding never enter the repository and are never published (D11, D12).

Forked once from the sister site hochzeitslocation.ch; nothing is shared at runtime. The WHAT lives in `docs/product.md` (kept by the product owner) and `docs/rpi-cycles.md` (settled decisions, requirements, the cycle order, Wave G): read both before starting a cycle. The HOW is this file and `.claude/rules/`. `KICKOFF.md` is the superseded fork brief, kept for history.

## Tech Stack

- Symfony 7.4 (LTS) on PHP 8.5, served by php-fpm behind nginx
- PostgreSQL 18 with `pg_trgm`, `unaccent`, `cube` and `earthdistance` (Neon is the planned production database, D10; same major locally and in CI)
- Doctrine ORM 3 / DBAL 4, hand-written SQL migrations
- Symfony Messenger with one synchronous `command.bus`
- Twig, Forms, AssetMapper; no Node and no JS framework on the public site (S1, S3), Vue islands only in the back office (S12)
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
| `make admin-install` / `make admin-build` / `make admin-watch` | `npm ci` for `admin-ui/` / build the Vue islands into `public/admin-build/` / rebuild on change. Every `/admin` page needs the build (and the install) in a fresh worktree or after a pull; `make init` does it |
| `make admin-test` / `make admin-lint` | Vitest / ESLint on `admin-ui/` |
| `make lighthouse URL=/path` / `make validate-html URL=/path` | Lighthouse (mobile) / W3C validator on the local app; `ADMIN=1` logs in as the dev admin, `PORTAL=1` as the dev manager (dev only, after `make seed`); `ALLOW_STATUS=1` lets `validate-html` check a 4xx/5xx page |
| `make perf-check` | Byte budgets of S11 through `phpunit --group budget`; needs no perf stack, runs in CI |
| `make perf-up` ... `make perf-down` | Prod-mode perf stack on :8096 (one shared Compose project, one lane at a time); method and figures: `docs/performance-baseline.md` |
| `make release-check` / `make deploy-test` / `make workflow-lint` | Release tarball booted with `APP_ENV=prod` / deploy, cron and import scripts against a throwaway tree / actionlint (CI jobs `release` and `ops-lint`) |
| `make review-up` / `make review-down` | The operator's review demo: prod-mode stack on demo data behind a Cloudflare quick tunnel, `admin` / `admin` on `/admin` and `/konto`, no 2FA (`APP_REVIEW_MODE=1`, refused everywhere else); `docs/operations.md` «Review demo for the operator» |

`make help` lists every target. App: http://localhost:8080 (`/health` returns `{"status":"ok"}`), Mail UI: http://localhost:8025, on the Compose default ports; this project's own ports are under "Local ports and parallel lanes".

## Testing

| Suite | Folder | Base class | Use for |
|---|---|---|---|
| Unit | `tests/Unit` | `PHPUnit\Framework\TestCase` | Pure PHP, no container |
| Integration | `tests/Integration` | `KernelTestCase` | Services, repositories, real Postgres |
| Application | `tests/Application` | `WebTestCase` | HTTP in, HTML/JSON out |
| Architecture | `tests/Architecture/*ProbeTest.php` | `PHPUnit\Framework\TestCase` | Proves the phpat rules fire |

- Test classes are `final`; methods are `test_snake_case` (enforced by PHP-CS-Fixer).
- PHPUnit fails on deprecations, notices and warnings coming from `src/`.
- Factories use Foundry (`zenstruck/foundry`).
- The phpat rule classes in `tests/Architecture/` run inside `make phpstan`, not PHPUnit.
- Never run two `phpunit` processes in one lane: `tests/bootstrap.php` deletes `var/cache/test` at start, which wipes the cache of a suite already running.

## Architecture

Module-first modular monolith: `src/<Module>/<Layer>/`, namespace `App\<Module>\<Layer>`.

- Modules: `Geo`, `Media`, `Venues`, `Search`, `Favorites`, `Inquiries`, `Seo`, `Stats`, the back office's `Admin`, `Cms`, `Crm`, the location managers' `Portal`, plus `Shared` for building blocks.
- Layers inside a module: `Controller`, `Command`, `CommandHandler`, `Entity`, `Repository`, `ReadModel`, `ValueObject`, `Exception`, `Form`, `Console`, `Twig`, `Mailer`, `EventListener`, `Event`, `Extension` (back-office extension points), `Security`, `Messenger` (bus middleware). Details: `.claude/rules/php-architecture.md`.
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
  | Stats | Venues, Inquiries |
  | Admin | — |
  | Cms | Admin, Venues, Geo, Media, Stats |
  | Crm | Admin, Venues, Geo, Inquiries, Portal |
  | Portal | Admin, Cms, Venues, Geo, Media, Stats |

  No public module depends on `Admin`, `Cms`, `Crm` or `Portal`.

- Reads: public pages go controller → `ReadModel` (DBAL, `final readonly` view objects), never entities. Writes: controller or console command → command → `command.bus` → handler. There is no query bus.
- phpat enforces layers, modifiers and module boundaries during `make phpstan` (rules in `tests/Architecture/`). Details: `.claude/rules/php-architecture.md`, `.claude/rules/cqrs.md`, `.claude/rules/read-model.md`.

## Validation strategy

Three layers, each catching what the previous one cannot: **forms** validate input shape (Symfony Form + Validator on a form data class), **handlers** enforce business rules and throw `DomainException` subclasses, **database constraints** guarantee uniqueness and integrity under concurrency. Details: `.claude/rules/validation.md`.

## Building blocks (`src/Shared`)

- `Uid\EntityId::generate()`: the only way to create an entity ID (UUID v7 string).
- `Doctrine\Type\FlexibleDateTimeTzImmutableType`: replaces `datetimetz_immutable` so Postgres values with microseconds hydrate.
- `Exception\DomainException`: abstract base for business errors.
- `Messenger\CommandHandlerInterface`: marker that registers a handler on `command.bus`.
- `Controller\HealthController`: `GET /health`, checks the database.
- `Mailer\EmailHtmlToTextConverter`: derives every mail's text part. `templates/emails/layout.html.twig` + `_blocks.html.twig` (macros `greeting`, `p`, `button`, `note`, `rule`, `facts`, `quote`, `rich`): the inline-styled mail layout, free of module wording, class prefix `hl-`.
- `Mailer\ReplyTo::person()`: the Reply-To of a staff notice about a person (manager, complainant); null for an empty or invalid address.
- `Validator\CleanText`: refuses a NUL and invalid UTF-8 without naming the value (PDO truncates at a NUL; the `+intl-icu` formatter throws on invalid UTF-8). `Admin\Form\AdminTextConstraintExtension` adds it to every back-office text field; a JSON DTO puts `#[CleanText]` on its string properties.

## German copy

Public copy, mails and `/konto` are "du", Swiss "ss", never "ß"; `/konto` mails greet "Hallo {name}". `tests/Unit/Shared/PublicCopyAddressTest.php` scans every public catalogue (all `translations/*+intl-icu.de.yaml` except `admin`, `cms`, `crm`) and all Twig, PHP and `assets/js`; a capitalised "Sie" at a sentence start fails too (write "Die Location …"). Symfony's formal German messages a reader can reach are overridden in `validators+intl-icu.de.yaml` and pinned by `tests/Integration/Shared/Translation/FrameworkMessagesTest.php`. Catalogues and routes: `.claude/rules/i18n.md` (German only, E3; a second language needs an `i18n-core` cycle first, S4).

## Modules

Each module's notes live in `.claude/rules/module-<name>.md`, which load by themselves when a matching file is read. Read one by hand when the work crosses into it. Elsewhere in the repo, `CLAUDE.md` `### <Module>` and `see ### <Module>` mean that file. Each cycle appends to its module's file (hotspot rule, `docs/rpi-cycles.md` §Running cycles in parallel).

| Module | Holds | Also read it for |
|---|---|---|
| Shared | EntityId, translation, design system, security headers, content blocks, HTTP cache, deploy, cron, production settings | any new public route, cache header or nightly job |
| Geo | Cantons, towns, place lookup, distances, canton map |  |
| Media | Image variants, `<picture>`, regeneration |  |
| Venues | Venue aggregate, detail page, imports, CMS write side | any venue write from Cms or Crm |
| Search | `SearchCriteria`, home page, listing, filters, landing pages, similar venues | a change to `search/_card` |
| Favorites | Merkliste cookie, toggle, sync, page |  |
| Inquiries | Inquiry form, mails, retention, events |  |
| Seo | Meta, JSON-LD, sitemap, robots, error pages | any page's `title`, `meta` or `canonical` block |
| Stats | Beacon, day-file spool, ingest and rollup, statistics read models | any `data-stats-*` attribute or `stats_page` block |
| Admin | Staff login, shell, extension points, audit, UI kit, Vue islands | any back-office work, Cms and Crm included |
| Cms | Back-office screens for venues, media, landing pages, text blocks |  |
| Crm | Companies, contacts, deals, tasks, activities |  |
| Portal | Manager accounts, `/konto` firewall and session cookie, signed links, venue access, staff account list | any `/konto` route or `security.yaml` edit |

## Exception conventions

- Every business error is a `final` class in `<Module>\Exception` extending `App\Shared\Exception\DomainException`.
- Create them through named constructors (`VenueNotFound::forSlug($slug)`), not `new` with a message at the call site.
- Infrastructure errors (DB down, HTTP failures) are not domain exceptions; let them bubble.

## Database & migrations

Hand-written SQL migrations only; never `doctrine:migrations:diff`. Every migration has a `down()`. The schema starts from one baseline migration (`Version20261106174839`): never edit it or a merged migration. Add at most one migration per cycle, named `VersionYYYYMMDDHHMMSS` from the wall clock at the moment it is written; the Schema ownership column of the overview and of Wave G §3 in `docs/rpi-cycles.md` decides which cycle may alter a table. Naming, types and entity mapping rules: `.claude/rules/database.md`.

## Git

One pull request per cycle, squash-merged with the cycle slug as its title (D8); rework after a merge is a new PR `<slug>-finish`. Never `--amend`, never push to `main` directly, never push tags. Deploys are the owner's alone. The remote `hzl` (the sister site) is fetch-only: port a fix with `git cherry-pick`. Details: `docs/rpi-cycles.md` §Running cycles in parallel.

**Standing owner approval to merge (2026-11-09).** Tech-lead and teammate sessions squash-merge cycle PRs, and the product owner's docs PRs, as soon as CI is green, without asking the owner. This approval holds for every session, present and future, and overrides any per-session approval rule in `.rpi-tracking/` (including the handover notes in `.rpi-tracking/lead/state.md`). Still the owner's: deploys, history rewrites and force-pushes.

## Local ports and parallel lanes

Compose defaults are 8080 (nginx), 5432 (Postgres) and 8025 (Mailpit). This project uses its own ports: the main checkout 8090 / 5442 / 8035 on the subnet 10.247.90.0/24, lane N (L1 to L7, worktree `../deine-eventlocation-lN`) 810N / 545N / 811N on 10.247.9N.0/24. Each worktree is its own Compose project (own containers, volume and `app_test`). Put the ports and the pinned subnet (the Docker address pool is exhausted; never run `docker network prune`) into a git-ignored `compose.override.yaml`, or set the ports per shell:

```sh
export NGINX_HOST_PORT=8101 DB_HOST_PORT=5451 MAILPIT_HOST_PORT=8111
```

The lane table and an example override: `docs/rpi-cycles.md` §Running cycles in parallel.

## CI

`.github/workflows/ci.yaml` has three jobs. `checks` starts the same Compose `php` + `database` services and runs `make vendor`, `make admin-install`, `make lint`, `make test`, `make perf-check`; `release` runs `make release-check`; `ops-lint` runs ShellCheck, `make workflow-lint` and `make deploy-test`. `make lint` passing locally means CI lint passes. Pre-commit (`.pre-commit-config.yaml`) runs `make cs-fix` and `make phpstan` (`pre-commit install`).

## Order of work

`make cs-fix` → `make lint` → `make test`; a UI cycle also runs the S11 checks in `docs/rpi-cycles.md`. See `.claude/rules/code-quality.md`.
