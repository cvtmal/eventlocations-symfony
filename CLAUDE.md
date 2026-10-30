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

Built in #1 (design system): design tokens in `assets/styles/abstracts/_tokens.scss` (CSS custom properties on `:root`) and the Sass entry `assets/styles/app.scss`, compiled by `symfonycasts/sass-bundle` with the dart-sass binary from the PHP image (hotspot: later cycles append one `@use` line for their own partial). `App\Shared\Twig\IconExtension` provides `{{ icon('name', {label?, class?}) }}` from the Lucide sprite `assets/icons/sprite.svg`. `templates/base.html.twig` has the blocks `title`, `meta`, `canonical`, `hreflang`, `stylesheets`, `jsonld`, `body_class`, `hero`, `main`, `scripts`; the header, menu and footer partials live in `templates/layout/` and the shared components in `templates/components/`. `/_styleguide` (dev and test only) renders every component. `make sass` builds the CSS (it runs automatically before `make test` and in `make init`), `make sass-watch` rebuilds on change, `make lighthouse URL=/path` writes reports to `var/lighthouse/`, and `make validate-html URL=/path` runs the Nu Html Checker. Frontend conventions: `.claude/rules/frontend.md`.

### Geo

Cantons, towns, postcode/town lookup, distances.

Built in #3:

- Tables: `cantons` (26 rows seeded by the migration; primary key `code`, the lower-case canton code — a documented exception to `EntityId`, since it is a code-keyed vocabulary row; `bfs_number`, `slug`, `svg_id`, centroid), `towns` (UUID `id`; one row per `(canton_code, name)`, lowest ZIP, `slug`, `bfs_number`, `lat`/`lng`, municipal `population`) and `town_zips` (every ZIP of a town). Entities in `Geo\Entity`; FK targets for later modules are `towns.id` and `cantons.code`.
- SQL functions `geo_fold(text)`, `geo_fold_de(text)` (ü → ue) and `geo_search_key(text)` (both folds). New SQL that compares place names must fold through them so it matches the GIN trigram index `idx_towns_search_key` on `geo_search_key(name)`. The GiST index `idx_towns_earth` on `ll_to_earth(lat, lng)` serves radius queries. Both are expression indexes, invisible to DBAL and deliberately unmapped.
- `app:geo:import-towns [path]`: idempotent import of `data/geo/towns.csv` (console → `ImportTowns` → handler → `TownRepository` upserts). Never deletes towns, only stale ZIP rows; reports stale towns. Run by `make seed` and once after each deploy's migrations.
- Read models: `PlaceLookup::suggest(string $q): list<PlaceSuggestion>` (cantons, then towns; ZIP prefix, name prefix, word prefix, trigram; by population; ≤ 8) and `TownLocator::withinKm(string $townId, float $km): list<TownDistance>` (0 < km ≤ 200, nearest first, centre town included).
- Labels: the `Canton` enum (`Geo\ValueObject`, implements `TranslatableInterface`) and the `geo` domain (`translations/geo+intl-icu.de.yaml`, keys `canton.<code>`). Canton suggestions carry only the code.
- Map: `{{ canton_map() }}` inlines `assets/maps/cantons.svg` (`<g id="cantons">` with `<path id="ch-<code>">`, `<g id="lakes">`); source, licence and build recipe in `assets/maps/LICENSES.md`. Dataset source note: `data/geo/SOURCE.md`.

### Media

Image variant generation, `<picture>` Twig helper.

**Built in #5 (`venue-media`).** Media depends on nothing but Shared; it knows paths and pixels, never venues.

- **Classes:** `Media\ImageVariantGenerator::generate(string $sourceFile, string $targetDirectory, string $basename): GeneratedImage` renders the S9 variants with gd at import time (never on request): widths 360/640/960/1280/1920 up to the EXIF-oriented original's width (a single `[<width>]` below 360), AVIF q52 s6, WebP 80, progressive JPEG 82, as `<dir>/<basename>-<w>.<ext>`. Orientation applied, metadata and ICC stripped (no conversion), alpha flattened on white, every file checked (`imageavif()` returns true on failure) and stale widths removed. Formats are detected at runtime (`availableFormats()`); without AVIF a host writes WebP + JPEG and the stored `formats` say so. `Media\MediaPublisher` (`copyVariants()`, `publishDocument()`, `removeVenue()`), `Media\MediaPaths` (all paths and `documentUrl()`), value objects `Media\ValueObject\{ImageFormat, ImageVariants, GeneratedImage, Picture}`, exceptions `Media\Exception\{MissingOriginal, UnreadableImage, InvalidMediaPath}` (an encoder failure is a `\RuntimeException`).
- **Paths:** originals under `MEDIA_ROOT` (env, `app.media_root`; dev `var/media`, test `var/test-media/originals`); `venue_photos.original_path` and `venue_documents.path` are relative to it. Variants at `public/media/<venues.number>/<photo-id>-<w>.<avif|webp|jpg>`, PDFs at `public/media/<number>/<document-id>.pdf` (`app.media_public_dir`; test `var/test-media/public`), URLs `/media/…`, served statically by nginx, git-ignored.
- **Two-step photo write:** the columns are NOT NULL and the id exists only after the constructor, so generate into a staging directory first, create `VenuePhoto(…, $generatedImage)`, then `MediaPublisher::copyVariants()` under the id. A changed original gets a new photo id.
- **Twig:** `{{ venue_picture(picture, sizes='(min-width: 900px) 520px, 100vw', loading='lazy', priority=false, widths=null, class='') }}` renders `<span class="picture">` + SVG placeholder (`fill` = dominant colour, no `style`) + `<picture>` with AVIF/WebP `<source>`s for the stored formats and a JPEG `<img width height>` (the original's size). Cards pass `widths=[360, 640, 960]`; the one LCP image `priority=true`. Read models build `Picture` from a row with `ImageVariants::fromJson($row['variants'])`; a photo without alt row uses `'picture.alt_fallback'|trans({name, position}, 'media')`. `{{ document_url(venueNumber, documentId) }}` → `/media/<n>/<id>.pdf`. Styles: `assets/styles/media/_picture.scss`.
- **Regenerate:** `make sf c="app:media:regenerate [--venue=<number>]"` (lives in Venues) re-renders variants in place and re-publishes PDFs; it continues past missing originals and exits 1 if any were skipped. URLs stay the same, so `/media` is not `immutable` across it (#19).
- **Demo media:** `App\Story\DemoMedia` draws 30 originals (`MEDIA_ROOT/demo/photo-NN.jpg`) and caches their variants in `var/demo-media/<fingerprint+hash>/`, shared by dev and test. The first `make seed` on a checkout is cold (~22 s on an M4 Pro), later ones copy only (~10 s).
- **Tests:** test images are drawn at runtime (`tests/Support/Media/TestImages.php`, no binary fixtures); media tests clean up with `MediaFiles::removeDirectory()`.

### Venues

Venue aggregate (translations, terms, rooms, photos, documents, reviews), detail page, import.

**Built in #4 (`venue-schema`).** Migration `Version20261030114442`; mapping `orm.mappings.Venues`.

- **Tables:** `venues` is the aggregate root. `venue_translations`, `venue_slug_history`, `venue_terms`, `venue_rating_highlights`, `venue_reviews`, `rooms` → `room_translations`, `venue_documents` → `venue_document_translations` and `venue_photos` → `venue_photo_translations` hang off it with `ON DELETE CASCADE`. `towns`, `cantons` and `taxonomy_terms` are referenced with `RESTRICT`.
- **`venues`:** `number` is an identity column (`GENERATED ALWAYS`, unique). It is the stable public number and the Merkliste id (S7), not the PK. `tier_rank` (1–5) is generated from `tier` with `Tier::rank()`'s ranks; order listings by it. `canton_code` is always the town's canton: the entity sets it from the town, the DB does not check it. There is no `venues.slug`: the slug is per locale in `venue_translations` (unique per locale), together with the summary (≤ 200), description, meta description and parking text. Read them with `TranslationJoin::localized('venue_translations', 'venue_id', 'v.id', 't')`; the same works for rooms, documents and photos. The name stays on `venues`. `room_count`, `largest_room_m2` and `max_room_height_m` are written by the write side from the rooms (DemoStory now, #9 later). `rating_score`/`rating_count` are both NULL for an unrated venue.
- **Terms:** `taxonomy_terms (term_group, code)` holds 90 rows seeded by the migration: 7 groups, `position` 1..n per group, and `primary_position` 1–11 for the building chips shown before "Alle Gebäudearten". `venue_terms (venue_id, term_group, term_code, is_main)` has a composite FK to it. `is_main` marks the main building and the main setting (at most one per venue and group, a partial unique index). Filter by code directly on `venue_terms`.
- **Vocabularies** in `App\Venues\ValueObject`: `TermGroup`, `Term` (VO: group + code), `Tier` (`rank()`), `VenueStatus`, `RatingLabel::fromScore()` (< 7.7 → none), `RatingHighlight` (`icon()` → #1's sprite ids), `OwnCatering`, `FloorMaterial`, `Seating`, `ReviewerRole` and `Access` (derived, not stored; #6 sets the thresholds). Labels are in the `venues` catalogue: `<context>.<value>` (`tier.premium`, `rating_highlight.food`) and `term.<group>.<code>` (`term.building.castle`). Every enum on a CHECK column is pinned to its `IN` list by `VocabularyChecksTest`.
- **Entities / repositories:** `App\Venues\Entity\{Venue, VenueTranslation, VenueSlugHistory, TaxonomyTerm, VenueTerm, VenueRatingHighlight, VenueReview, Room, RoomTranslation, VenueDocument, VenueDocumentTranslation, VenuePhoto, VenuePhotoTranslation}`, unidirectional `ManyToOne` only. `Venue` has no setters: use `relocate()`, `changeTier()`, `publish()`/`unpublish()`, `changeCapacity()`, `changeContact()`, `changeDistances()`, `answerFaq()`, `rate()` and `summariseRooms()`. Decimals are passed as strings (`'8.4'`) and read back as floats. `Room::offerSeating()/seatingMin()/seatingMax()` cover the four seating column pairs. `VenuePhoto` throws `Venues\Exception\RoomOfAnotherVenue` for a room of another venue. `VenueRepository`, and `TaxonomyTermRepository::byGroup()`.
- **Demo data:** one Foundry factory per entity in `src/Factory/` (no `TaxonomyTerm` factory: look the rows up). `App\Story\DemoStory` creates 60 fictional venues: every canton, the tiers 3/6/9/12/30, 1–5 rooms, 0–3 reviews, 0–2 documents, about a third unrated, no photos. It is seeded and idempotent (it skips when venues exist), needs the imported towns, and is loaded by `AppStory`, so `make seed` loads it. The faker locale is `de_CH`.
- **Tests:** `tests/Integration/Venues/VenueSchemaMigrationTest.php` has one rejection case per constraint, and a check that fails when a new constraint has no case. `tests/Integration/Migrations/SchemaInSyncTest.php` runs `schema:validate` inside `make test`.
- **Photos (#5, `Version20261030125302`):** `venue_photos` has `width`, `height` (the oriented original), `dominant_color` (`#rrggbb`) and `variants` JSONB `{"widths": [...], "formats": ["avif", "webp", "jpg"]}`, all NOT NULL with CHECKs. `VenuePhoto(Venue, ?Room, int $position, string $originalPath, GeneratedImage $image)`, `recordVariants()`, `width()`, `height()`, `dominantColor()`, `variants(): ImageVariants`. `VenuePhotoFactory` writes rows only (1600×1067, widths 360–1280), no files. DemoStory now gives every venue 3–8 photos (some rooms 1–2) and real PDFs (superseding "no photos" above); `app:media:regenerate` lives here (`Venues\Console\RegenerateMediaCommand` → `Venues\Command\RegenerateMedia`).

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
