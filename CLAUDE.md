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
| `make test` | Prepare `app_test`, build Sass and the islands, run PHPUnit, then Vitest |
| `make test-short` | Run PHPUnit, stop at the first failure |
| `make cs-fix` / `make cs-check` | Fix / check coding standards |
| `make phpstan` | PHPStan level 8 + architecture rules |
| `make lint` | `cs-check` + `phpstan` + `lint-translations` + `admin-lint` (the CI gate) |
| `make admin-install` | `npm ci` for `admin-ui/` (in the `node` tools service) |
| `make admin-build` / `make admin-watch` | Build the Vue islands into `public/admin-build/` / rebuild on change |
| `make admin-test` / `make admin-lint` | Vitest / ESLint on `admin-ui/` |
| `make lighthouse URL=/path ADMIN=1` / `make validate-html URL=/path ADMIN=1` | The same tools logged in as the dev admin (dev only) |

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

- Modules: `Geo`, `Media`, `Venues`, `Search`, `Favorites`, `Inquiries`, `Seo`, the back office's `Admin`, `Cms`, `Crm`, plus `Shared` for building blocks.
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
  | Crm | Admin, Venues, Geo, Inquiries |

  No public module depends on `Admin`, `Cms` or `Crm`.

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
- `/` is the YAML route `home` in `config/routes.yaml` (302 → `listing`, see `### Search`); no controller.
- `Mailer\EmailHtmlToTextConverter`: derives every mail's text part (`twig.mailer.html_to_text_converter`). `templates/emails/layout.html.twig` + `_blocks.html.twig` (macros `greeting`, `p`, `button`, `note`, `rule`, `facts`, `quote`): the inline-styled mail layout, free of module wording, class prefix `hl-`. Built in #14, reused by #30.

Languages, catalogues and routes: `.claude/rules/i18n.md`.

## Modules

Each cycle appends to its module's subsection (hotspot rule, `docs/rpi-cycles.md` §Running cycles in parallel).

### Shared

`EntityId`, `DomainException`, translation helpers, Swiss formatting Twig extension, HTTP cache helpers, legal pages, health.

Built in #0: the building blocks above, the placeholder `HomeController` (`home`, `/`), and the first migration with the Postgres extensions.

Built in #2: `Shared\Translation\{EnabledLocale, LocalizedRoutes, HreflangAlternates, TranslationJoin}`, `Shared\Twig\{SwissFormatExtension, HreflangExtension}` (`swiss_number`, `swiss_date`, `swiss_range`, `hreflang_alternates`), `templates/shared/_hreflang.html.twig`, catalogue stubs, and `make lint-translations`, which `make lint` now also runs. Rules: `.claude/rules/i18n.md`.

Built in #1 (design system): design tokens in `assets/styles/abstracts/_tokens.scss` (CSS custom properties on `:root`) and the Sass entry `assets/styles/app.scss`, compiled by `symfonycasts/sass-bundle` with the dart-sass binary from the PHP image (hotspot: later cycles append one `@use` line for their own partial). `App\Shared\Twig\IconExtension` provides `{{ icon('name', {label?, class?}) }}` from the Lucide sprite `assets/icons/sprite.svg`. `templates/base.html.twig` has the blocks `title`, `meta`, `canonical`, `hreflang`, `stylesheets`, `jsonld`, `body_class`, `hero`, `main`, `scripts`; the header, menu and footer partials live in `templates/layout/` and the shared components in `templates/components/`. `/_styleguide` (dev and test only) renders every component. `make sass` builds the CSS (it runs automatically before `make test` and in `make init`), `make sass-watch` rebuilds on change, `make lighthouse URL=/path` writes reports to `var/lighthouse/`, and `make validate-html URL=/path` runs the Nu Html Checker. Frontend conventions: `.claude/rules/frontend.md`.

Built in #9: `Shared\Messenger\ResetClosedEntityManagerMiddleware` (before `doctrine_transaction`: resets an EntityManager a failed command closed, so the next dispatch in the same process works; the stock `doctrine_ping_connection` acts only for worker-consumed messages).

### Geo

Cantons, towns, postcode/town lookup, distances.

Built in #3:

- Tables: `cantons` (26 rows seeded by the migration; primary key `code`, the lower-case canton code — a documented exception to `EntityId`, since it is a code-keyed vocabulary row; `bfs_number`, `slug`, `svg_id`, centroid), `towns` (UUID `id`; one row per `(canton_code, name)`, lowest ZIP, `slug`, `bfs_number`, `lat`/`lng`, municipal `population`) and `town_zips` (every ZIP of a town). Entities in `Geo\Entity`; FK targets for later modules are `towns.id` and `cantons.code`.
- SQL functions `geo_fold(text)`, `geo_fold_de(text)` (ü → ue) and `geo_search_key(text)` (both folds). New SQL that compares place names must fold through them so it matches the GIN trigram index `idx_towns_search_key` on `geo_search_key(name)`. The GiST index `idx_towns_earth` on `ll_to_earth(lat, lng)` serves radius queries. Both are expression indexes, invisible to DBAL and deliberately unmapped.
- `app:geo:import-towns [path]`: idempotent import of `data/geo/towns.csv` (console → `ImportTowns` → handler → `TownRepository` upserts). Never deletes towns, only stale ZIP rows; reports stale towns. Run by `make seed` and once after each deploy's migrations.
- Read models: `PlaceLookup::suggest(string $q): list<PlaceSuggestion>` (cantons, then towns; ZIP prefix, name prefix, word prefix, trigram; by population; ≤ 8) and `TownLocator::withinKm(string $townId, float $km): list<TownDistance>` (0 < km ≤ 200, nearest first, centre town included).
- Labels: the `Canton` enum (`Geo\ValueObject`, implements `TranslatableInterface`) and the `geo` domain (`translations/geo+intl-icu.de.yaml`, keys `canton.<code>`). Canton suggestions carry only the code.
- Map: `{{ canton_map() }}` inlines `assets/maps/cantons.svg` (`<g id="cantons">` with `<path id="ch-<code>">`, `<g id="lakes">`); source, licence and build recipe in `assets/maps/LICENSES.md`. Dataset source note: `data/geo/SOURCE.md`.

Built in #10 (`filter-region`):

- `Geo\ReadModel\PlaceResolver::resolve(string $place): ?ResolvedPlace` (town UUID or 4-digit ZIP; a ZIP means its most populous town, the rule of `ListingSql`; memoized per value, misses too, `kernel.reset`). `ResolvedPlace`: `name`, `zip`, `lat`, `lng`, `label()` ("8712 Stäfa").
- `PlaceSuggestion` has `lat`/`lng` (null for a canton), so town entries of `/_search/places` now end in `"lat", "lng"`. A map pin uses the affine fit in `assets/js/region-filter.js` (2.29 SVG units per km, 96.4 % of towns land in their own canton).

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
- **`venues`:** `number` is an identity column (`GENERATED ALWAYS`, unique). It is the stable public number and the Merkliste id (S7), not the PK. `tier_rank` (1–5) is generated from `tier` with `Tier::rank()`'s ranks; order listings by it. When there is a town, `canton_code` is the town's canton: the entity sets it from the town, the DB does not check it. There is no `venues.slug`: the slug is per locale in `venue_translations` (unique per locale), together with the summary (≤ 200), description, meta description and parking text. Read them with `TranslationJoin::localized('venue_translations', 'venue_id', 'v.id', 't')`; the same works for rooms, documents and photos. The name stays on `venues`. `room_count`, `largest_room_m2` and `max_room_height_m` are written by the write side from the rooms (DemoStory now, #9 later). `rating_score`/`rating_count` are both NULL for an unrated venue.
- **Terms:** `taxonomy_terms (term_group, code)` holds 90 rows seeded by the migration: 7 groups, `position` 1..n per group, and `primary_position` 1–11 for the building chips shown before "Alle Gebäudearten". `venue_terms (venue_id, term_group, term_code, is_main)` has a composite FK to it. `is_main` marks the main building and the main setting (at most one per venue and group, a partial unique index). Filter by code directly on `venue_terms`.
- **Vocabularies** in `App\Venues\ValueObject`: `TermGroup`, `Term` (VO: group + code), `Tier` (`rank()`), `VenueStatus`, `RatingLabel::fromScore()` (< 7.7 → none), `RatingHighlight` (`icon()` → #1's sprite ids), `OwnCatering`, `FloorMaterial`, `Seating`, `ReviewerRole` and `Access` (derived, not stored; #6 sets the thresholds). Labels are in the `venues` catalogue: `<context>.<value>` (`tier.premium`, `rating_highlight.food`) and `term.<group>.<code>` (`term.building.castle`). Every enum on a CHECK column is pinned to its `IN` list by `VocabularyChecksTest`.
- **Entities / repositories:** `App\Venues\Entity\{Venue, VenueTranslation, VenueSlugHistory, TaxonomyTerm, VenueTerm, VenueRatingHighlight, VenueReview, Room, RoomTranslation, VenueDocument, VenueDocumentTranslation, VenuePhoto, VenuePhotoTranslation}`, unidirectional `ManyToOne` only. `Venue` has no setters: use `relocate()`, `changeTier()`, `publish()`/`unpublish()`, `changeCapacity()`, `changeContact()`, `changeDistances()`, `answerFaq()`, `rate()` and `summariseRooms()`. Decimals are passed as strings (`'8.4'`) and read back as floats. `Room::offerSeating()/seatingMin()/seatingMax()` cover the four seating column pairs. `VenuePhoto` throws `Venues\Exception\RoomOfAnotherVenue` for a room of another venue. `VenueRepository`, and `TaxonomyTermRepository::byGroup()`.
- **Demo data:** one Foundry factory per entity in `src/Factory/` (no `TaxonomyTerm` factory: look the rows up). `App\Story\DemoStory` creates 60 fictional venues: every canton, the tiers 3/6/9/12/30, 1–5 rooms, 0–3 reviews, 0–2 documents, about a third unrated, no photos. It is seeded and idempotent (it skips when venues exist), needs the imported towns, and is loaded by `AppStory`, so `make seed` loads it. The faker locale is `de_CH`.
- **Tests:** `tests/Integration/Venues/VenueSchemaMigrationTest.php` has one rejection case per constraint, and a check that fails when a new constraint has no case. `tests/Integration/Migrations/SchemaInSyncTest.php` runs `schema:validate` inside `make test`.
- **Photos (#5, `Version20261030125302`):** `venue_photos` has `width`, `height` (the oriented original), `dominant_color` (`#rrggbb`) and `variants` JSONB `{"widths": [...], "formats": ["avif", "webp", "jpg"]}`, all NOT NULL with CHECKs. `VenuePhoto(Venue, ?Room, int $position, string $originalPath, GeneratedImage $image)`, `recordVariants()`, `width()`, `height()`, `dominantColor()`, `variants(): ImageVariants`. `VenuePhotoFactory` writes rows only (1600×1067, widths 360–1280), no files. DemoStory now gives every venue 3–8 photos (some rooms 1–2) and real PDFs (superseding "no photos" above); `app:media:regenerate` lives here (`Venues\Console\RegenerateMediaCommand` → `Venues\Command\RegenerateMedia`).

**Built in #8 (`venue-detail-page`).** No migration; Composer `symfony/rate-limiter`.

- **Routes:** `venue_show` `GET /hochzeitslocation/{slug}` (`Venues\Controller\VenueDetailController`; slug requirement `VenueDetailController::SLUG_REQUIREMENT`): current slug 200, `venue_slug_history` slug 301 to the current one, unpublished (status or no `de` translation) 410, else 404; 200 and 301 send `public, s-maxage=600, stale-while-revalidate=86400`, no session. `venue_phone` `GET /hochzeitslocation/{slug}/telefon` (`VenuePhoneController`): HTML page by default, JSON `{"phone", "display"}` for `Accept: application/json`; `private, no-store`, `X-Robots-Tag: noindex, nofollow`, `Vary: Accept`; limiter `phone_reveal` (`config/packages/rate_limiter.yaml`: sliding window, 20 per 10 minutes per client IP, pool `cache.phone_reveal`) → 429 + `Retry-After`. Tests clear that pool in `setUp()` (an array pool is reset by the test client between requests).
- **Read model:** `Venues\ReadModel\VenueDetailQuery::resolveSlug()` (1 query, `SlugMatch` incl. the venue `number`) / `detail(int $number, string $locale, bool $includeUnpublished = false)` (3 queries, `VenueDetail` with `DetailFactGroup`, `DetailDistance`, `DetailDocument`, `DetailReview`, `DetailRoom`, `DetailSeating`, `Picture`s) / `phone()` (1). The page never selects `phone`, only `has_phone`; the number is not in the page HTML. Published = the `ListingSql` predicate, repeated as a const. CMS preview (#24): `detail($number, $locale, includeUnpublished: true)`; it still needs a translation in the locale or the default (else null), and the detail templates read only `venue` (no `app.request` route checks), so they render outside `venue_show` too. `Venues\ValueObject\PhoneNumber` formats E.164 for display (`044 123 45 67`, `0800 123 456`). Test fixtures: `tests/Support/Venues/DetailVenues` (`full()`, `minimal()`).
- **Templates:** `templates/venues/detail/show.html.twig` + `_hero`, `_gallery`, `_nav`, `_actions`, `_head`, `_rating`, `_facts`, `_rooms`, `_room_dialogs`, `_faq`, `_location`, `_contact`, `_documents`, `_reviews`; `templates/venues/phone.html.twig`. Strings in `venues` under `detail.*`. Slots: `{% block map %}` at the end of `<section id="standort">` (#15; `venue.lat`, `venue.lng`, `venue.street`, `venue.zip`, `venue.townName`), `<section id="anfrage">{% block inquiry %}{% endblock %}</section>` after Dokumente (#14; "Verfügbarkeit prüfen" links to `#anfrage`), and the Merken include `favorites/_merken_button.html.twig` with `{number, name, label, variant: 'detail'}` (#7's partial, #13 adds the behaviour; `_actions.scss` targets `.merken--detail .merken__button`). "Ähnliche Locations" is Search's fragment, embedded with `render(controller('App\\Search\\Controller\\SimilarVenuesController', {venueId}))`: one kernel sub-request per page, so request/response listeners (#16's CSP nonce, security headers) must act on the main request only.
- **Assets:** styles `assets/styles/venues/_detail.scss` (+ `venues/detail/*`, incl. `@media print`, DP-16); JS `share.js` (Web Share API, popover fallback, copy link), `print.js` (shows the hidden Drucken button), `phone-reveal.js`; icons `check`, `house`. The page preloads the 600 font weight (CLS).

**Built in #9 (`venue-content-import`).** Migration `Version20261030140037`: `venues.content_key` (folder name, `uk_`, kebab CHECK) and `venues.content_hash` (SHA-256, only with a key), `rooms.content_key` (unique per venue), and the table `content_imports` (one row per run that is not a dry run: counts, `errors` JSONB `[{key, messages}]`).

- **Command:** `make sf c="app:venues:import [path] [--dry-run] [--only=key] [--prune] [--force]"` (`Venues\Console\ImportContentCommand`) reads `<CONTENT_ROOT>/venues/<key>/` (`CONTENT_ROOT` in `.env`, git-ignored `content/`; never commit content). Format, rules and options for the owner and #21: `docs/content-format.md`; schema `docs/content-format.schema.json` (draft-07, `opis/json-schema`), its enums pinned to the PHP enums and `taxonomy_terms` by `ContentSchemaVocabularyTest`.
- **Flow:** one `ImportVenueContent` dispatch (one transaction) per folder; a failing folder is reported and the run goes on (exit 1). `ContentReader` validates everything before any write; an unchanged `content_hash` skips the venue (a second run writes nothing); then `UnpublishMissingContent` (`--prune`) and `RecordContentImport`; the handlers purge the HTTP cache after their commit (`PurgeVenueCache` per changed or unpublished venue, `PurgeListingsCache` once per run that changed something) through `Venues\HttpCachePurger` (`purgeVenue(int)`, `purgeListings()`; no-op `NullHttpCachePurger` until #19 swaps the alias; implementations never throw). The console only dispatches.
- **Reuse (CMS #24/#25, one write path):** `Venues\VenueSlugs::assign()` (slug + history), `TownResolver` (throws `UnresolvedTown`), `RoomSummary::apply()` (room facts from the venue's rooms), `PhotoPipeline` (`openStaging` → `stage` → `add` → flush → `publish`, `discardStaging`; `ValueObject\StagedPhoto`), `DocumentPipeline` (`store` → `add` → flush → `publish`), `PublishedMedia` (`revert()` on failure), and after the commit `RemoveVenueFiles`, `PurgeVenueCache`, `PurgeListingsCache` (all with `DispatchAfterCurrentBusStamp`). Compose them; never duplicate their rules.
- **Rules:** a venue with a `content_key` is written only by the import. The key is immutable; the number stays. Rooms keep their row by `content_key`, photos by `(room key, original_path)` and documents by `path`, where originals are content-addressed (`MEDIA_ROOT/content/<key>/<sha256>.<ext>`): a renamed or reordered file keeps its id and variants, changed bytes get a new photo. Every other child set (terms, highlights, reviews, all translations) is replaced. Positions move in two flushes (offset +10000) because the position uniques are not deferrable. Slugs come from the name (`SlugAllocator`: name, `-<town-slug>`, `-2`…); the old slug goes to `venue_slug_history`, a rename back deletes that row, and history slugs stay reserved. Files of removed rows are deleted by `RemoveVenueFiles` (dispatched with `DispatchAfterCurrentBusStamp`, so only after the commit).
- **Tests:** build fictional folders at runtime with `tests/Support/Content/ContentFolder.php`; the integration tests use the `RunsContentImport` trait (rolled-back transaction, media dirs cleaned). `Venues\HttpCachePurger` is `Tests\Support\Http\RecordingHttpCachePurger` in the test env (`venues`: purged numbers in order, `listings`: count).

**Built in #31 (`venue-wedding-facts`).** Migration `Version20261030160506`. The owner facts of S14 (DP-17, DATA-4); nothing in Search, the six new groups have no filter until #33.

- **Columns:** `venues.price_level` (enum `PriceLevel`), `capacity_seated`, `capacity_standing` (1–50000, the owner's numbers, not compared with `capacity_max`), `party` (enum `PartyAllowed`), `has_curfew` (NULL = unknown), `curfew_at` `TIME(0)`, `instagram` (`https://`), all nullable; `venue_translations.ambience` (≤ 60), `capacity_note`, `party_note`, `opening_hours` (≤ 250), kept verbatim. `chk_venues_curfew_at` is `curfew_at IS NULL OR has_curfew IS TRUE`: the plain form is NULL for a time with `has_curfew` NULL, and a NULL CHECK passes.
- **Terms:** 13 groups and 146 rows (superseding "7 groups" and "90 rows" above): `TermGroup::{Ceremony, Amenity, Dining, Vendor, Usage, Language}` with 56 rows, `primary_position` NULL. New rows need a migration.
- **Entities:** `Venue::changePriceLevel()`, `changeSeatingCapacity(?seated, ?standing)`, `describePartyRules(?party, ?hasCurfew, ?curfewAt)`, `changeInstagram()`, `VenueTranslation::describeWedding(?ambience, ?capacityNote, ?partyNote, ?openingHours)`; bare-name getters. `revise()` and the constructor did not change, so a caller writes the texts with a second call.
- **Format (`docs/content-format.md`):** `price_level`, `capacity.seated`/`.standing`, `party.allowed`/`.has_curfew`/`.curfew_at`, `contact.instagram`, `terms.<six groups>`, `translations.<locale>.ambience`/`.capacity_note`/`.party_note`/`.opening_hours`, all optional; an omitted field resets to NULL on update. `curfew_at` is `HH:MM` at every boundary (read `to_char(…, 'HH24:MI')` in SQL, `H:i` in PHP); midnight is `'00:00'`, `24:00` is rejected. `ContentReader` reports `/party/curfew_at` without `has_curfew: true`. `ContentFormat::VERSION` stays 1.
- **Detail page:** `Venues\ReadModel\DetailWedding` is `venue.wedding` (`hasAny()`, lists `ceremony`, `amenities`, `dining`, `usage`, `vendors`, `languages` of `Term`), `venue.instagram` feeds `hasContact()`. The six groups never join `factGroups`. `templates/venues/detail/_wedding.html.twig` follows `_facts` with its `venue-facts__*` classes, strings `detail.wedding.*`; "Ausstattung" (`amenity`) is a line beyond DP-17's list because FI-7 filters on it. Icon `camera`, because Lucide ships no brand marks.
- **Demo data and tests:** `DemoStory::createWeddingFacts()` runs after the media step, for the even-index venues, with its own seeded RNG; `DetailVenues::full()` carries every fact, `minimal()` none. Round trip: `tests/Integration/Venues/ImportWeddingFactsTest.php`.

**Built in #21 (`legacy-data-import`).** Migration `Version20261030183112`; no new Composer package.

- **Location is optional:** `venues.zip`, `town_id`, `canton_code`, `lat`, `lng` are nullable, and most real venues have only a canton. `chk_venues_address` (`zip` and `town_id` together, `street` needs a town), `chk_venues_coordinates` (both or neither), `chk_venues_town_canton` (a town has a canton). `venue_translations.region` (≤ 60, verbatim) holds the place of a venue without a canton. Reads must `LEFT JOIN towns` and never assume a town. `down()` fails while such venues exist and never deletes them.
- **Entity:** `Venue::__construct(…, ?Town, ?string $zip, ?string $street, ?float $lat, ?float $lng, ?string $contentKey = null, ?Canton $canton = null)`, `relocate()` the same shape; the town wins over a passed canton, and the getters are nullable. `VenueTranslation::changeRegion()`/`region()`. Factory `VenueFactory::withoutLocation(?Canton)`; `DetailVenues::withoutPlace()`, `inCantonOnly()`, `inRegionOnly()`. DemoStory's venues 52–55 (0-based) are in these shapes, so the states stay visible and the Ort tab stays on.
- **View:** `Venues\ValueObject\VenuePlace` (`final readonly`, `TranslatableInterface`; public `townName`, `canton`, `region`; `of()`, `isKnown()`): `{{ place|trans }}` gives the town, `Kanton Zürich`, the text verbatim, or `''`. `ListingCard::$place`, `SimilarVenue::$place` and `VenueDetail::$place` replace `$townName`. `VenueDetail::hasLocation()` (town, coordinates or distances) guards `#standort` and its nav item, so `{% block map %}` sits inside a conditional section and #15 must render only with coordinates. Title and share text have three forms (town, canton, name alone); `detail.title_*` is a placeholder until #16's DP-1.
- **Format:** only `name`, `tier`, `capacity.max`, `translations.de.summary` are required. `address.canton` is an enum of the 26 codes (the import uses `getReference()`, so a bad code cannot reach the FK), and `ContentReader` rejects half an address. `SlugAllocator::candidates($name, ?$townSlug)` goes `x`, `x-2`, `x-3` without a town.
- **Export:** `app:legacy:export-content <csv> [path] [--dry-run]` (`Venues\Console\ExportLegacyContentCommand` → `LegacyContentExporter`) writes #9 folders, never the DB. Its input is the owner's Wix CSV, copied to the git-ignored `content/owner/`, the only production content source. `WixExport::rows()` (BOM, multi-line fields; a shifted row fails the file), `WixRichText::paragraphs()` (recursive, NFC, no U+200B, NBSP-only paragraphs dropped, `IMAGE` skipped with its caption), `LegacyVenueText` (`description()` rules 1–4, `summary()`, `key()`), `LegacyVenueMapper` (`Region` → canton, region text, Liechtenstein skip, phone to E.164, bad contact values dropped and noted), `LegacyFolderWriter` (owns `name`, `capacity.max`, `address.canton`, `contact.{phone,inquiry_email,website}`, `translations.de.{summary,description,region}`; keeps every other key; `tier: basic` only when absent; byte-stable, so a second run writes nothing), `InvalidWixExport`. Keys are `<ascii-name-slug>-<id8>`, and an existing folder ending in `-<id8>` is reused. Console output holds counts and `<key>: <reason>`, never a contact value. Tests use `tests/Support/Content/WixCsv.php`, a fictional CSV built at runtime.
- **#32 reuses** `WixExport`, `WixRichText`, `LegacyVenueText` and the key rule, stays off the paths above, and sets `tier`.

### Search

`SearchCriteria`, listing read model, counts, ordering, similar venues, autocomplete endpoints, listing page.

**Built in #6 (`search-query`).** Migration `Version20261030115117` (indexes only).

- **`Search\ReadModel\SearchCriteria`** (hotspot, #6 owns it; filter UIs only render it) is the filter state and URL codec. Build it with `none()`, `fromQuery($request->query, $knownTermsQuery->all())`, `withPage()` or `withCanton()`. It owns the parameter names: `canton`; `place` (town UUID or 4-digit ZIP) + `radius` (0–50 km, default 5, 0 = the place only); `guests`; `q`; `setting[] building[] infra[] furniture[] tech[] surroundings[]` (known codes of the matching `TermGroup`; `infra`/`tech` map to `infrastructure`/`technology`); `access[]`; `rooms` 1–6; `area` 50…300; `height` 2.5…5.0; `page` 1–1000. Invalid values are dropped, never a 4xx/5xx (it reads `$query->all()` once and type-checks). `toQuery()` is canonical (fixed key order, sorted lists, page 1 and radius 5 omitted): build URLs with `http_build_query($criteria->toQuery())`. `KnownTermsQuery` loads the 90 `taxonomy_terms` once per request (`kernel.reset`).
- **`ListingQuery`**: `results(SearchCriteria, string $locale): ListingPage` (`cards`, `total`, `page`, `perPage` = `PER_PAGE` 48, `lastPage`; #7 answers 404 for page > lastPage) and `count(SearchCriteria): int`. Three queries (cards, photos, count; two when the page is empty). Order (S6): `tier_rank`, then `md5(id || seed)` with the seed = today in Europe/Zurich from `Psr\Clock\ClockInterface` (`MockClock` in tests), then `id`. Published means `status = 'published'` plus a default-locale translation. `ListingCard` carries number, slug, name, tier, capacity, town, summary, rating, highlights and up to 8 venue-level `CardPhoto`s. `CardPhoto` is read from `to_jsonb(venue_photos)`, so #5's columns appear without an SQL change; build a `Media\ValueObject\Picture` from it only when `hasVariants()`.
- **Filter SQL** lives once in `ListingSql` (shared by cards and count; `cardStatement()`/`countStatement()` expose it for EXPLAIN). Groups combine with AND; inside a group `setting`/`building` are OR and every other term group and `access` AND. **New term filters: AND unless the group is setting/building.** Guests: `capacity_max ≥ g AND (capacity_min IS NULL OR capacity_min ≤ g)`. Access thresholds come from `Venues\ValueObject\Access::maxDistanceKm()` (1/10/30 km; NULL = not near).
- **Indexes:** `idx_venues_earth` (GiST on `ll_to_earth(lat, lng)`) and `idx_venues_search_key` (GIN trigram on `geo_search_key(name)`), unmapped expression indexes like Geo's. The radius centre must be a joined `MATERIALIZED` CTE (`WITH centre … CROSS JOIN centre c`), or the planner does not use the GiST. Name search folds through `geo_fold_de`/`geo_fold`, guarded by `geo_fold_de(:q) <> ''`.
- **`SimilarVenuesQuery::forVenue(string $venueId, string $locale): list<SimilarVenue>`**: 12 other published venues, same canton first, then overlapping capacity, then nearest; first venue photo as `?CardPhoto`.
- **Endpoints** (unlocalized, `X-Robots-Tag: noindex`, no session or cookie): `GET /_search/count?<listing query>` (`_search_count`) → `{"count": n}`, `Cache-Control: public, max-age=60, s-maxage=600, stale-while-revalidate=86400`; `GET /_search/places?q=` (`_search_places`) → `{"places": [{"type":"canton","canton":"zh","label":"Zürich"} | {"type":"town","id","name","zip","canton"}]}`, `public, max-age=3600, s-maxage=86400`.
- **Tests:** `tests/Unit/Search/*`, `tests/Integration/Search/*` (every filter, order, paging, photos, query count via `doctrine.debug_data_holder`) and `ListingQueryPerformanceTest` (DemoStory ×10 cloned by SQL; heaviest combination < 20 ms; `SEARCH_PERF_REPORT=1` prints the timings). Read models are built by hand in tests (`ListingQueryTest::enabledLocale()`).

**Built in #7 (`listing-page`).** No migration.

- **`ListingController`** (`listing`, `GET /hochzeitslocations`, localized): `SearchCriteria::fromQuery` → `ListingQuery::results` → 404 when `page > lastPage` (an empty result is page 1 with the empty state); `#[Cache(public, s-maxage=600, stale-while-revalidate=86400)]`, no session, no `X-Robots-Tag` (#16 decides `noindex,follow`). `/` is the YAML route `home` (framework `RedirectController`, 302 to `listing`, query kept, `ignoreAttributes: [_canonical_route]`); the name `home` stays for every `path('home')`.
- **`Search\Twig\ListingExtension`**: `listing_path(criteria, page?)` (canonical URL from `toQuery()`), `listing_path_without(criteria, param|params, value?)` (a chip's remove link: drops whole params or one list value, always drops `page`), `card_picture(CardPhoto, name): ?Picture` (#6's recipe, alt fallback `picture.alt_fallback`), `rating_label(score)`, `venue_path(slug)` (`generate('venue_show', {slug})`; the slug must match #8's requirement).
- **`TopCantonsQuery::top(int $limit = 9): list<CantonCount>`** (published venues per canton on `ListingSql`, most first, then code) feeds "Andere suchten auch nach"; **`ValueObject\PageLinks::around(page, lastPage)`** gives the pagination window (first, last, three around the current page, `null` = "…").
- **Seams** (pass translated labels; include with `only`):
  - `search/_filter_bar.html.twig` (#7 hotspot, `criteria`) includes each slot file `search/filter/_{region,guests,more}.html.twig` twice with `criteria` + `part` (`chips`, then `trigger`); each renders `<li class="filter-bar__item">`s (chips via `_chip` + `listing_path_without`; the trigger button + its `_overlay` shell whose footer is "Filter löschen"). #10/#11/#12 replace their own slot file and never edit the bar.
  - `search/filter/_hidden_state.html.twig` (`criteria`, `except`): hidden inputs for the other groups' filters in an overlay's GET form (never `page`).
  - `search/_card.html.twig` (`card` = ListingCard shape, `index` 0 = LCP / 1 = eager / default 99, `action` `merken`|`none`; #13 reuses it; the title link stretches over the card and each photo slide carries its own `tabindex="-1" aria-hidden="true"` link to the venue, so the photos navigate on tap and still swipe), `search/_card_facts.html.twig` (`capacity_min`, `capacity_max`, `town_name`), `search/_card_rating.html.twig` (`score`, `count`, `highlights`), `search/_related_searches.html.twig` (`links` list<{label, href}>, `title`; #17 passes its landing pages), `search/_hero.html.twig` (`heading`, `total`, `criteria`; #17 passes its h1).
  - `favorites/_merken_button.html.twig` (`number`, `name`, `label`, `variant` `card`|`detail`): the no-JS `POST /merkliste/{number}` form (literal S5 path, no CSRF yet); owned by #13, #8 includes it with `variant: 'detail'`.
- **Tests:** `ListingControllerTest` (rendering, carousel loading rules, chips, pagination, 404, headers), `ListingHtmlBudgetTest` (48 cards × 8 photos ≤ 60 KB gzip), `ListingExtensionTest`, `TopCantonsQueryTest`, `PageLinksTest`, `tests/Application/Shared/HomeRedirectTest`.

**Built in #8 (`venue-detail-page`):** `Search\Controller\SimilarVenuesController` has no route on purpose; the Venues detail template embeds it (`render(controller(…, {venueId}))`) and it renders `templates/search/_similar_venues.html.twig` (12 compact cards built with #7's `card_picture()`, `search/_card_facts` and `components/_badge`; "Alle Locations" → `listing`), styles `assets/styles/search/_similar-venues.scss`, strings `similar.*`.

**Built in #10 (`filter-region`).** No migration. `_filter_bar`, `_overlay` and `SearchCriteria` are unchanged.

- **Slot:** `search/filter/_region.html.twig` (chips, trigger, overlay) plus `search/filter/_region_form.html.twig` (`criteria`, `selected`), the GET form `#filter-region-form`. Its submit button sits in the shell's footer and joins through `form="filter-region-form"`. `.filter-bar` text must not contain "Karte" (`ListingControllerTest`), so the map's name stays in `aria-label`.
- **Twig:** `selected_place(criteria): ?ResolvedPlace` on `ListingExtension` (third constructor argument `PlaceResolver`). Chip: a town id shows "Stäfa", a ZIP "8712 Stäfa", unknown values the ZIP or "Ort".
- **`assets/js/live-count.js`:** the shared count; #11 and #12 add no script tag. Contract: `.claude/rules/frontend.md` (Overlays). Strings: `filter.show_results`, rendered three times with `count` 0, 1, 2 and `formatted: '%n'` for the 2.
- **`assets/js/region-filter.js`:** tabs, canton combobox, map (roving tabindex, `role="button"`, `aria-pressed`, tooltip through `el.style.left/top`, never `setAttribute('style')`, CSP #16), place combobox, radius, pin and "Filter löschen". One form with two tabs: only the active tab's hidden field is enabled; the Ort tab sets `inert` on the map. Closing the overlay discards pending changes; text without a choice clears the value (four digits count as that ZIP). Without JS: `<noscript><select name="canton">`, a text field for a ZIP, the native radius.
- **Verified in Chromium 156 (not Firefox or Safari):** a `formdata` listener's edits reach the submitted URL, and `preventDefault()` on Esc in a `keydown` keeps a popover open, so Esc closes the list first.
- **Styles:** `assets/styles/search/_filter-region.scss`, tokens `--c-dropdown`, `--c-map-radius`. `[hidden]` has no global rule: a class that sets `display` needs its own `&[hidden] { display: none; }`. Grid layout only under `:popover-open`.
- **Budget:** `AssetBudgetTest` holds `overlay.js + live-count.js + region-filter.js` to 6 KB gzip.

**Built in #21 (`legacy-data-import`).** No migration.

- Listing and similar queries `LEFT JOIN towns`; a venue without a town is a card and is counted. `ListingCard::$place` and `SimilarVenue::$place` are `VenuePlace` (see `### Venues`), and `_card_facts.html.twig` takes `place` and renders the pin only when it is known. `TopCantonsQuery` skips NULL cantons, and `SimilarVenuesQuery` ranks with `(…) IS TRUE` because a NULL canton sorts first under `DESC`.
- `Search\ReadModel\FilterDataQuery::has($filter)`: `SELECT EXISTS` on `ListingSql`'s published predicate, memoized per request (`ResetInterface`); an unknown key throws. Twig: `filter_has_data('place')` on `ListingExtension`. **#12 adds its keys (`access`, `rooms`, `area`, `height`) to `FilterDataQuery::FILTERS`**, so hiding is one mechanism.
- Hidden Ort/PLZ tab: while no published venue has a town and `criteria.place` is null, `_region_form.html.twig` adds `.region-filter--canton-only` (CSS hides the tablist and the Ort panel) and `disabled` to `#filter-region-place` and `#filter-region-radius`. Every element stays, because `region-filter.js` reads them unguarded and has no budget for guards. A bookmarked `?place=` keeps the full form, and the first venue with a town brings the tab back.

### Favorites

Merkliste cookie codec, toggle endpoint, `/merkliste` page.

_Nothing built yet; the owning cycle appends here._

### Inquiries

Inquiry form, entity, mails.

**Built in #14 (`inquiry-form`).** Migration `Version20261030152935`; mapping `orm.mappings.Inquiries`; Composer `symfony/security-csrf`.

- **Table `inquiries`** (entity `Inquiries\Entity\Inquiry`, IQ-1): `venue_id` → `venues(id) ON DELETE SET NULL`, so the row outlives the venue; a snapshot in `venue_number`, `venue_name`, `venue_slug`, `recipient_email` (the venue's address, always) and `delivered_to` (the real envelope recipient, differs under the override); `locale`, `occasion`, `guest_count` (1–5000), `event_date`, `message`, `first_name`, `last_name`, `email`, `phone`; `fingerprint` (below); `venue_mail_status`, `guest_mail_status` (`pending|sent|failed|skipped`, enum `MailStatus`), `venue_mail_sent_at`. The typed columns are the full payload; no IP address or user agent is stored. `Occasion` is the Anlass enum (`TranslatableInterface`, `occasion.<code>`). CHECK lists are pinned by `tests/Integration/Inquiries/InquiryVocabularyChecksTest.php`. #29 reads this table and never alters it.
- **Route** `inquiry_submit` `POST /hochzeitslocation/{slug}/anfrage` (`Inquiries\Controller\SubmitInquiryController`): unknown 404, unpublished 410, no address 404, a history slug is accepted. Order: honeypot (`website`; 303 to the confirmation, nothing stored) → form → fill time (`Form\FillTimeToken`, signed `started`, at least 3 s, no maximum age) → limiter `inquiry_submit` (5 per hour per IP, pool `cache.inquiry_submit`, 429 + `Retry-After`) → `SubmitInquiry` → `DeliverInquiry` → `InquiryEvents::submitted()`. Invalid 422, a failed venue mail 503, both with the values kept; every response `private, no-store`, `X-Robots-Tag: noindex, nofollow`, no cookie.
- **One guest action, one row (double submit):** the rule is server-side, because the form has no JS and PHP finishes a POST the browser cancelled (nginx 499). `SubmitInquiry` computes `ValueObject\InquiryFingerprint` (HMAC-SHA-256 with `kernel.secret` over the venue id and the normalised payload: NFC, collapsed whitespace, lower-case e-mail; a corrected typo is a new inquiry), takes `pg_advisory_xact_lock` on it, and returns the newest row with that fingerprint from the last 10 minutes as `SubmittedInquiry(id, isRepeat: true)` whatever its mail status, else inserts. `DeliverInquiry` then claims the row (`InquiryRepository::claim()`: `SELECT … FOR UPDATE`, held until commit, across both SMTP calls), so a repeat waits for a delivery in progress and answers with its real outcome. `sent` sends nothing and gives the same 303; `failed` delivers again; `pending` with the claim free means the earlier delivery ended without a result, so it delivers too. `InquirySubmitted` is published for a new row only. The duplicate still costs one `inquiry_submit` token. Use transaction-level advisory locks only: `persistent: true` and any pooler would keep a session-level one. The fingerprint is not a person key: keyed, per venue, over the whole payload, and purged with the row. #29 must not copy it.
- **Commands:** `SubmitInquiry` (business rules: venue published with an address, guests 1–5000, date not before today in Europe/Zurich; returns `SubmittedInquiry`; `InquiryNotAccepted`, `GuestCountOutOfRange`, `EventDateInPast`), `DeliverInquiry` (its own transaction, so the row is committed before any SMTP call; claims the row; venue mail, then the guest copy only if that went out; returns `DeliveryOutcome`; records each mail's outcome. The venue mail catches `TransportExceptionInterface|RfcComplianceException|HandlerFailedException`, because `Mailer::send()` unwraps only transport errors; anything else bubbles with nothing sent and the row `pending`. After the venue mail nothing may undo that record, so the guest copy catches `\Throwable`. A row recorded as `sent` is not mailed again, nor its guest copy, even a `failed` one. What this does not cover: a process death or a lost database connection between the SMTP acknowledgement and the commit leaves `pending`, and the next delivery mails the venue again), `PurgeInquiries`. Delivery is synchronous; #20 measures SMTP, and drain-on-terminate is the fallback above about 2 s.
- **CSRF:** stateless (`symfony/security-csrf`, `csrf.yaml`). The `_token` value is the constant `csrf-token`, the check is `Sec-Fetch-Site`, else `Origin`/`Referer`, so the form fits a cached page. A POST with none of these headers is refused; application tests send `HTTP_SEC_FETCH_SITE: same-origin`.
- **Twig:** `{{ inquiry_form(venue, form = null) }}` (`Inquiries\Twig\InquiryFormExtension`) fills #8's `{% block inquiry %}`: nothing without an inquiry address (`.venue-inquiry:empty` hides the section), the confirmation on a signed `?anfrage=gesendet`, else the form. No query, no session, the same HTML for every visitor. The confirmation URL is constant per venue; `#anfrage` goes on after `UriSigner::sign()`, which hashes fragments. `_actions.html.twig` links "Verfügbarkeit prüfen" to `#anfrage`, else `#kontakt`, else leaves it out. Templates `inquiries/_form`, `_confirmation`; styles `assets/styles/inquiries/_form.scss`; no JS. `InquiryFormFactory::create(VenueDetail)` is the one place that builds the form.
- **Mail:** `Inquiries\Mailer\InquiryMailer` (`sendToVenue()` returns the envelope recipient; `sendToGuest()`). From is `MAILER_FROM` (`mailer.yaml` `headers`), the guest copy's Reply-To is `%site.contact_email%`, the venue mail's the guest. `INQUIRY_RECIPIENT_OVERRIDE` redirects venue mails only and adds `X-Inquiry-Original-Recipient`; `.env` leaves it empty, `.env.dev` and `.env.test` set it, `ProductionRecipientOverrideTest` pins production. A context key `email` would collide with Twig's message variable, hence `guest_email`.
- **Logs:** `Inquiry {inquiry}: the {mail} mail failed ({exception_class} at {file}:{line}).` and the same shape in `InquiryEvents`. The project has no Monolog, and the default `Logger` prints the message with its placeholders filled and drops the rest of the context, so every value an operator needs must be a placeholder in the message. Never the exception's message: it can quote an address. Tests read the text from a real `Logger` on `php://memory`.
- **Event for #29:** `App\Inquiries\Event\InquirySubmitted` (`inquiryId`), published by `InquiryEvents` from the controller after both commits, in a `finally`, once per stored row, also when the venue mail failed; a repeat publishes nothing. So the outcome can change after the event (`failed` to `sent`, on a retry): read it from `inquiries` when showing it, do not keep a copy. A resend action (#29) can re-dispatch `DeliverInquiry` for a `failed` row; the handler claims the row itself, so it needs no lock of its own. Honeypot hits never reach it. `InquiryEvents` catches `\Throwable` per dispatch and logs: a subscriber cannot fail the guest, but a throwing one stops later subscribers of the same event. Deviation from the cycle's wording: a handler cannot run code after its own `doctrine_transaction` commit.
- **Retention (LEG-2):** `app:inquiries:purge [--dry-run]`, `%inquiries.retention_months%` = 12, by `created_at`; daily cron is #20's. There is no purge event: #29's `crm_inquiry_links` cascades and contacts fall under `app:crm:purge`, so #29 must not copy inquiry personal data anywhere else.
- **E-mail rule** (`InquiryData`): strict `Email`, then `EMAIL_SHAPE` (`Assert\Sequentially`, they share one message): an ASCII local part (a non-ASCII one needs SMTPUTF8), no Unicode separator or control character anywhere (Postgres' `\s` is Unicode-aware, PCRE's without `/u` is not), an IDN domain stays valid. Never copy a CHECK's regex into PHP without testing both engines.
- **Validation copy** is in `translations/validators+intl-icu.de.yaml` (`inquiry.<field>.<rule>`): the validator translates constraint messages in the `validators` domain, not per form. Everything else is in `inquiries`.
- **Tests:** `tests/Support/Inquiries/` has `InquiryFormFields::valid()` (a back-dated token), `SubmitsInquiries` (client, transaction, limiter pool, `post()`, `listen()`), `RecordingMailer` (`MailerInterface` is inlined in the test container, so hand-build `InquiryMailer` with it; a failure surfaces as from the real `Mailer::send()`), `SmtpSink` (a real SMTP server without SMTPUTF8, which can refuse a recipient; Mailpit offers SMTPUTF8, so `DeliverInquiryOverSmtpTest` uses this). Mail errors through the real pipeline: a throwing `MessageEvent` listener (`SubmitInquiryMailErrorTest`). Double submits with real commits: `SubmitInquiryConcurrencyTest` runs `post-inquiry.php` twice, holds the lock the POSTs need until `pg_locks` shows both blocked, then lets go, so the overlap is forced and not timed. `InquiryEmailRuleTest` pins the form's e-mail rule against the real `chk_inquiries_email`: the form may refuse more than the CHECK, never less. Each mail logs two events, queued then sent: use `assertEmailCount()`, or filter `getMailerEvents()` on `!isQueued()`. A dead SMTP: set `MAILER_DSN` before `createClient()` (`SubmitInquiryDeliveryFailureTest`).

### Seo

Sitemap, robots, JSON-LD builders, landing page definitions.

_Nothing built yet; the owning cycle appends here._

### Admin

Back-office foundation at `/admin` (S12): staff login, shell, extension points, audit log, Vue islands.

**Built in #22 (`admin-foundation`).** Migration `Version20261030152714`; mapping `orm.mappings.Admin`; Composer `symfony/security-bundle`, `scheb/2fa-bundle`, `scheb/2fa-totp`, `bacon/bacon-qr-code`; `config/packages/security.yaml` is #22's hotspot (others use `#[IsGranted]`).

- **Dev admin:** `make seed` creates `admin@hochzeitslocation.test`, password `admin-dev-password`, TOTP secret `JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP` (`App\Story\AdminStory`; public on purpose, dev data only). `make sf c="app:admin:reset-2fa admin@hochzeitslocation.test"` lets you enrol your own app.
- **Tables:** `admin_users` (e-mail stored lower-case, `role` admin|editor, `totp_secret_encrypted` NULL until enrolled, `is_active`; deactivated, never deleted) and `admin_audit_log` (append-only, so no `updated_at`; `actor_type` admin_user|console|system, `actor_id` only for admin_user, `changes` JSONB). `AdminUser(string $id, …)` takes its id from the caller (`EntityId::generate()` before dispatch), so `auditSubject()` knows it.
- **Security:** one firewall on `^/admin(/|$)`, nothing elsewhere, so public pages stay session-free (S10); the session cookie is `SameSite=Strict`, `HttpOnly`, path `/admin`. Form login (5 tries per 15 minutes), mandatory TOTP (`TotpSecretCipher`, sodium secretbox, key `ADMIN_TOTP_KEY`; #20 generates production's), first-login enrolment at `/admin/2fa/einrichten` (`RequireTotpEnrolmentListener`), 5 wrong codes per user per 15 minutes (`TotpThrottleListener` + `TotpFailureHandler`, limiter `admin_totp`), 2-hour idle timeout (`IdleTimeoutListener`, session key `admin.last_seen`), `Cache-Control: no-store, private` and `X-Robots-Tag: noindex, nofollow` on everything under `/admin` (`AdminResponseHeadersListener`). A deactivated account is refused after the password check (`RefuseInactiveAdminListener`, a `CheckPassportEvent` listener: `checkPreAuth` would disclose it, and scheb stops the event `checkPostAuth` runs on for a user with TOTP). **Anything that decides by "is this the back office?" asks `Security\AdminArea` (`contains()`, `isJson()`), never the raw path:** the router and the firewall decode the path, so `/%61dmin/benutzer` is `/admin/benutzer` to them. `Security\AdminIdentity` is the security user, built per request by `AdminUserProvider`; the session holds fingerprints only, so a password, role or 2FA change ends that user's sessions. `ROLE_ADMIN` includes `ROLE_EDITOR`.
- **Routes:** `admin_dashboard` `/admin`, `admin_login`, `admin_2fa_login`, `admin_2fa_check`, `admin_totp_enrol`, `_logout_admin` (POST only, declared in `config/routes/security.yaml` because Symfony's generated route allows any method), `admin_users*` `/admin/benutzer…`, `admin_audit_log` `/admin/audit-log` (filters `actor`, `subject_type`, `subject_id`, `from`, `to`), `admin_styleguide` `/admin/_styleguide` (dev, test). New routes: `options: [LocalizedRoutes::UNLOCALIZED => true]`, a name starting `admin_`, `#[IsGranted]`. `AdminRoutesAreProtectedTest` walks the router (trait `ChecksAdminRoutes`) for every route and method as anonymous, in the code step, unenrolled, editor and admin, canonical and percent-encoded. It fills every route parameter with a UUID, so a new route is covered without touching the test only if its parameters accept one: with a stricter requirement such as `\d+` the walker gets a 404 and fails loudly until it is taught that parameter's value (`ChecksAdminRoutes::adminEndpoints()`). `ExtensionPointsTest` runs the same walk on the fake module's routes.
- **Console:** `app:admin:create-user <email> --role=admin|editor [--name=…]`, `app:admin:reset-2fa <email>`, `app:admin:reset-password <email>`.
- **Audit:** a command implements `Shared\Messenger\AuditableCommand` (`auditSubject(): AuditSubject`, `auditSummary()`, never a password or secret) and `Admin\Messenger\AuditLogMiddleware` writes the row inside the transaction. `Admin\ReadModel\AuditLogQuery::forSubject($type, $id, $limit)` serves "Zuletzt bearbeitet von" and the CRM timeline.
- **Extension points** (`Admin\Extension`): implement `NavigationItem`, `DashboardWidget`, `RecordTab`, `RecordPanel` or `SearchProvider` in your module's `Extension` layer; autoconfiguration registers it. Twig: `admin_navigation()`, `admin_dashboard_widgets()`, `admin_record_tabs(type, id)`, `admin_record_panels(type, id)`; all filter by `requiredRole()` and sort by `priority()` descending. The fake module in `tests/Fixtures/Admin/` shows each one.
- **Shell:** `templates/admin/base.html.twig` blocks `title`, `body_class`, `breadcrumbs` (`<li class="breadcrumbs__item">`), `page_actions`, `page_header`, `main`, `aside`, `scripts`; `layout/_sidebar`, `_topbar`, `_flash`; the search trigger is the slot `admin/layout/_search_trigger.html.twig` (#23). Flashes are toasts: types `success`, `error`, the message a key of the `admin` catalogue. `templates/admin/form/_field.html.twig` renders one form row with its error linked to the input. Styles: `assets/styles/admin.scss` (append one `@use` per partial) with `--a-*` tokens, never loaded with `app.css`. Icons: `{{ admin_icon('name', label) }}` from `assets/icons/admin-sprite.svg`. Strings: the `admin` domain.
- **Vue islands:** one line in `admin-ui/src/islands.js`, the file `admin-ui/src/islands/<Pascal>Island.vue` (`<script setup>`, no `<style>`, no bare strings). In Twig: `{{ admin_island('hello', {greeting: '…'|trans({}, 'admin')}) }}`. `admin_island_assets(nonce = null)` closes `base.html.twig` and loads only what the page used.
- **JSON endpoints:** `/admin/api/<module>/…`, route names `admin_api_<module>_…`, class-level `#[IsGranted]` and `#[IsCsrfTokenValid('admin_api', tokenKey: 'X-CSRF-Token', tokenSource: IsCsrfTokenValid::SOURCE_HEADER, methods: [...])]` (the token is in `<meta name="csrf-token">`); validation errors answer 422 with `ValidationErrors::fromViolations()|fromForm()`, `{"errors": {"field": ["message"]}}`; 401 and 403 are JSON too.
- **Budgets (ADM-8):** `AdminAssetBudgetTest`: shared JS ≤ 60 KB gzip, each island ≤ 30 KB, admin CSS ≤ 30 KB.

### Cms

Back-office screens for venues, photos, landing pages and text blocks.

_Nothing built yet; the owning cycle appends here._

### Crm

Back-office companies, contacts, deals, tasks, activities and inquiries.

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
