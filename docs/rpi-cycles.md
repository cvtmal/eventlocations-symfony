# Hochzeitslocation: RPI cycles

_Last updated: 2026-10-30 (#0 project-skeleton and #2 i18n-foundation done; owner answered Q1, Q2, Q4–Q7) · Source: this file (scope, requirements, order), `docs/reference/` (screenshots of the original)_

We are building a Swiss wedding-venue listing site: one listing page with filters, one detail page per venue, and a Merkliste that needs no login. It copies the layout, style and content structure of eventlokale.ch's wedding search. It is **not** an eventlokale clone: there is no magazine, no providers, no event types, no Award, no back office.

This file turns that scope into `/rpi` cycles and sets the order to run them in. One cycle = one slug = one pass through Research → Plan → Implement → Review. A cycle ends with `make lint` and `make test` green and exactly one commit. The cycles are grouped in **waves**: cycles in the same wave depend only on earlier waves, so they can run in parallel sessions.

## How to run a cycle

1. `/rpi-research <slug>`, giving the cycle's section below as the task description, plus the sections **Settled decisions** and **Requirements** of this file. Cycles marked **Plan** in the overview have clear enough requirements to start at `/rpi-plan <slug>` instead.
2. `/clear` → `/rpi-plan <slug>` → `/clear` → `/rpi-implement <slug>` → `/clear` → `/rpi-review <slug>`.
3. If the review says `Needs Rework`, re-enter the phase it names. A cycle that others depend on counts as done only once its review says `Complete`.
4. `/rpi <slug>` tells you where you are if you lose your place.
5. When the cycle is `Complete`, update its row in the overview to `✅ Done` and add an **Outcome** paragraph under its section. Record what later cycles must know: routes, class names, table names, deviations, and anything carried forward. The Outcome is the handoff between parallel sessions, so write it for a reader who has not seen the code.

Artifacts land in `.rpi-tracking/{research,plans,changes,reviews}/<date>/<slug>-*.md`.

**Gates.** Some cycles depend on an owner question (see **Owner gates**). A gate blocks only the part of a cycle it names. Everything else in the cycle runs with the default given there.

## Important

- In `/rpi` cycles, never ask the owner to make a decision. Apply your own recommendation and record it in the research; the owner will follow your recommendation. The only exceptions are the **Owner gates** below.
- Every full cycle ends with exactly one commit, created when the owner says "commit". The commit message is the cycle slug, and the commit is pushed to `main` (see **Running cycles in parallel** for the rebase step). If a cycle needs rework, the rework goes in a new commit on top.
- Never copy eventlokale's texts, photos, logos, icons or font files into this repository, the demo data or the tests. We copy layout, structure, interaction and colour values. Reference screenshots stay in `docs/reference/` as documentation only. The one exception is the venue content from the legacy import (#21, Q4). It lives in `CONTENT_ROOT` / `MEDIA_ROOT` outside the repository and is never committed.
- Every UI cycle compares its result with the matching screenshot in `docs/reference/` at 390 px and 1440 px width, using Claude in Chrome against the local app. The live eventlokale page may be visited for behaviour questions: slowly, at most about 20 requests per session (Cloudflare rate-limits at ~35 rapid requests), never with forms submitted.
- The app must stay reachable locally at `http://localhost:${NGINX_HOST_PORT}` after every cycle, with demo data loaded (`make db-fresh && make seed`).

## Running cycles in parallel

The owner runs several sessions at once, one cycle each. These rules keep them from colliding.

1. **One git worktree per cycle.** `git fetch && git worktree add ../hochzeitslocation-<slug> -b <slug> origin/main`, then start the session in that directory. Never run two cycles in the same working tree.
2. **One Docker Compose project per worktree.** Compose names the project after the directory, so every worktree gets its own containers, its own Postgres volume and its own `app_test`. Give each lane its own host ports in a git-ignored `compose.override.yaml` or in the shell:

   | Lane | `NGINX_HOST_PORT` | `DB_HOST_PORT` | `MAILPIT_HOST_PORT` |
   |---|---|---|---|
   | main checkout | 8080 | 5432 | 8025 |
   | A | 8081 | 5433 | 8026 |
   | B | 8082 | 5434 | 8027 |
   | C | 8083 | 5435 | 8028 |
   | D | 8084 | 5436 | 8029 |

3. **Finishing.** After the review is `Complete` and the owner says "commit": `git fetch && git rebase origin/main`, then `make lint && make test` again, then one commit with the slug as its message, then `git push origin HEAD:main`. If the push is rejected, rebase and test again. Then `git worktree remove`.
4. **Migrations.** Name each migration `VersionYYYYMMDDHHMMSS` from the wall clock at the moment it is written. Two cycles in the same wave never alter the same table; the **Schema ownership** column of the overview says which cycle owns which table.
5. **Hotspot files.** Some files are owned by one cycle; later cycles only add lines to them, never restructure them:

   | File | Owner | Others may |
   |---|---|---|
   | `tests/Architecture/ArchitectureRules.php` (module table) | #0 declares **all** modules of this file up front | nothing; a needed change goes back to the owner as a design change |
   | `templates/base.html.twig`, `templates/layout/*` | #1 | fill the blocks #1 provides |
   | `assets/styles/app.scss` (list of partial imports) | #1 | append one `@use` line for their own partial |
   | `config/packages/translation.yaml`, `src/Shared/Translation/*` | #2 | nothing |
   | `translations/messages+intl-icu.de.yaml` (layout strings) | #1 | nothing; each module has its own domain file |
   | `src/Search/ReadModel/SearchCriteria.php` and its URL codec | #6 | nothing; filter UIs (#10–#12) only render it |
   | `templates/search/_filter_bar.html.twig` | #7 | fill their own `{% include %}` slot |
   | `CLAUDE.md` | #0 | append to their module's section |

6. **Concurrency.** Three to four sessions at a time is the sweet spot. More than that and the rebase step and the owner's reviews become the bottleneck.

## Overview and order of execution

| # | Slug | Wave | Requirements | Depends on | Schema ownership | Gate | Start at |
|---|---|---|---|---|---|---|---|
| 0 | `project-skeleton` | W0 | OP-1, GEN-2 | — | — | — | ✅ Done |
| 1 | `design-system` | W1 | GEN-3, A11Y-1, PERF-1 (CSS, fonts) | 0 | — | — | Research |
| 2 | `i18n-foundation` | W1 | GEN-1, SEO-6 | 0 | — | — | ✅ Done |
| 3 | `geo-reference-data` | W1 | FI-1 (data), FI-2 (data) | 0 | `cantons`, `towns` | — | Research |
| 4 | `venue-schema` | W2 | DATA-2, DP-5–DP-15 (data), LP-2 (data) | 2, 3 | `venues`, `venue_*`, `rooms`, `room_*`, `taxonomy_terms` | — | Research |
| 5 | `venue-media` | W3 | PERF-2, LP-3 (images), DP-2 (images) | 4 | `venue_photos` (columns for variants) | — | Research |
| 6 | `search-query` | W3 | LP-4, FI-5, FI-6, PERF-3 (query) | 4 | indexes only | — | Research |
| 7 | `listing-page` | W4 | LP-1–LP-3, LP-5, LP-6 | 1, 5, 6 | — | — | Research |
| 8 | `venue-detail-page` | W4 | DP-1–DP-12, DP-14–DP-16 | 1, 5, 6 | — | — | Research |
| 9 | `venue-content-import` | W4 | DATA-1 | 4, 5 | `content_imports` | — | Research |
| 10 | `filter-region` | W5 | FI-1, FI-2 | 3, 7 | — | — | Research |
| 11 | `filter-guests` | W5 | FI-3 | 7 | — | — | Plan |
| 12 | `filter-more` | W5 | FI-4 | 7 | — | — | Research |
| 13 | `merkliste` | W5 | MK-1–MK-4 | 7, 8 | — | — | Research |
| 14 | `inquiry-form` | W5 | DP-13, IQ-1–IQ-4 | 8 | `inquiries` | ~~Q6~~ (to the venue) | Research |
| 15 | `venue-map` | W5 | DP-10 (map) | 8 | — | Q3 (production key only) | Research |
| 16 | `seo-foundation` | W6 | SEO-1–SEO-3, SEO-5, SEC-1 | 7, 8 | — | — | Research |
| 17 | `seo-landing-pages` | W6 | SEO-4, LP-5 | 10, 16 | `landing_pages` (if needed) | — | Research |
| 18 | `legal-pages` | W6 | LEG-1, LEG-2 | 1, 13, 14 | — | ~~Q5~~ | Plan |
| 19 | `performance-caching` | W7 | PERF-1–PERF-3 | 10–17 | — | — | Research |
| 20 | `prod-deploy` | W7 | OP-2, OP-3 | 19, 21 | — | ~~Q1~~, ~~Q2~~ (Hostpoint + Neon) | Research |
| 21 | `legacy-data-import` | W5 | DATA-3 | 3, 9 | — | ~~Q4~~ (yes) | Research |

### Waves at a glance

```
W0  #0 project-skeleton
W1  #1 design-system  ‖  #2 i18n-foundation  ‖  #3 geo-reference-data
W2  #4 venue-schema                                   (#1 may still be running)
W3  #5 venue-media  ‖  #6 search-query                (#1 may still be running)
W4  #7 listing-page  ‖  #8 venue-detail-page  ‖  #9 venue-content-import
W5  #10 filter-region ‖ #11 filter-guests ‖ #12 filter-more ‖ #13 merkliste ‖ #14 inquiry-form ‖ #15 venue-map ‖ #21 legacy-data-import
W6  #16 seo-foundation  ‖  #18 legal-pages   →   #17 seo-landing-pages (needs #16)
W7  #19 performance-caching  →  #20 prod-deploy
```

**Critical path:** #0 → #2/#3 → #4 → #6 → #7 → #10 → #17 → #19 → #20. #1 runs alongside W1–W3 and must be done before W4. #9 and #21 are off the critical path until #20, which needs the real content; demo data from #4/#5 carries every UI cycle.

**Milestones.**
- After #8: you can browse the listing and detail pages locally with demo data. This is the first owner review of look and feel.
- After #21: the local app runs on the real ~758 venues. This is the second owner review, now with real photos and texts.
- After #15: the MVP is feature-complete (all filters, Merkliste, inquiry, map).
- After #19: launch-ready (SEO, legal pages, performance budgets met).
- After #20: live on hochzeitslocation.ch.

**What can move.** #9 can run any time after #5. #21 can run any time after #9, and the earlier the better, because #17's landing-page thresholds and #19's measurements should use the real data. #15 and #18 have nothing depending on them except #19 and can slip. #11 is small and can be folded into #12 if a lane is short of work.

## Owner gates

Each gate has a default, so no cycle is blocked as a whole. The owner answers here and the answer is recorded in this file. Only Q3 is still open.

| ID | Question | Answer (owner, 2026-10-30) | Consequences |
|---|---|---|---|
| ~~Q1~~ | Brand name and domain? | Brand **Hochzeitslocation**, domain **hochzeitslocation.ch** (already registered). | `site.name` = "Hochzeitslocation"; canonical host `https://hochzeitslocation.ch`. #20 decides apex vs `www` and 301s the other. Title suffix "\| Hochzeitslocation". The mail sender uses an address on `hochzeitslocation.ch` (#14, #20). The wordmark stays text until the brand design. |
| ~~Q2~~ | Production hosting? | **Hostpoint** (Swiss shared webhosting) for PHP, **Neon** for PostgreSQL. | #20 starts from the Laravel attempt's `../hochzeitslocation/deploy/hostpoint.md` (control panel, PHP binary path, cron, GitHub Actions deploy, media rsync) and from songrequest's cyon + Neon notes (`../songrequest/docs/rpi-cycles.md` #7: libpq without SNI needs the endpoint ID in the password; never percent-encode `DATABASE_URL`). It verifies on Hostpoint: PHP 8.5 availability, `pdo_pgsql`, gd/Imagick with AVIF, cron, SSH + git, and the round-trip to Neon's nearest EU region. The full-page cache (#19) is what keeps Neon's cold start and latency off the visitor's path. |
| Q3 | Google Maps Embed API key (free, HTTP-referrer restricted). | **Open:** the owner provides it later. | Default stays: map facade with a keyless "Auf Google Maps öffnen" link. Setting `GOOGLE_MAPS_EMBED_KEY` later switches on the embed without a deploy of code. |
| ~~Q4~~ | Real venue content? | **Yes, copy from the Laravel project.** | #21 is scheduled (W5) and is the production content source. The owner takes responsibility for the rights to the venue texts, photos and contact data, which originate from eventlokale.ch. The content stays outside the repository (see **Important**). |
| ~~Q5~~ | Legal entity for Impressum and Datenschutz? | **Pick n Roll GmbH**, Sonneggstrasse 61, 8006 Zürich, UID-Nr.: CHE-134.034.546 MWST. Contact email **hallo@hochzeitslocation.ch**. | #18 uses this verbatim; no phone number. The mailbox does not exist yet: #20 creates it on Hostpoint and proves it receives mail before go-live. #18 drafts the Datenschutz text from the processors in LEG-1 for the owner to review. |
| ~~Q6~~ | Where do inquiries go in production? | **Directly to the venue by email.** Later they also go to our own CMS/CRM, built later. | IQ-2 sends to the venue's inquiry address in production. `INQUIRY_RECIPIENT_OVERRIDE` is for dev and staging only (Mailpit). Every inquiry is stored in `inquiries` with its full payload and mail status, so the future CRM can read the history. |
| ~~Q7~~ | Order of the paid tiers? | **As observed on eventlokale.** | Beliebte Location → Empfehlung → Top Premium → Premium → no badge (S6). |

## Settled decisions

These are settled now so that parallel cycles do not each decide them differently. A cycle that finds strong evidence against one records it in its research and continues on the settled path. A settled decision changes only by an edit to this file.

### S1. Stack

- **Runtime:** Symfony 7.4 LTS on PHP 8.5, PostgreSQL 18, Doctrine ORM 3 / DBAL 4, Twig, Symfony Mailer, AssetMapper, nginx + php-fpm in Docker Compose (`php`, `nginx`, `database`, `mailpit`).
- **Same versions as `../songrequest`,** so its `CLAUDE.md`, `.claude/rules/*`, `Makefile`, `compose.yaml`, CI workflow and phpat tests can be copied and trimmed rather than invented.
- **No Node at runtime and no JS framework or library:** no Stimulus, Turbo, Swiper or jQuery. Build-time tools in a container are fine, e.g. the dart-sass standalone binary through `symfonycasts/sass-bundle`, or Lighthouse CLI for checks.
- **PostgreSQL over MariaDB.** No requirement here needs MariaDB, and Postgres gives us:
  - the owner's existing toolchain and rules (songrequest, DEP);
  - `pg_trgm` + `unaccent` for "Name eingeben" and the Ort/PLZ autocomplete, with typo and umlaut tolerance ("Zuerich", "Zurich" → Zürich);
  - `cube` + `earthdistance` for "within N km of a town" without PostGIS;
  - GIN indexes and partial indexes for facet filtering;
  - CHECK constraints, generated columns and `NULLS NOT DISTINCT` unique keys for the validation strategy.
  
  Shared-host availability is the one argument for MariaDB. Q2 answers it: Hostpoint runs PHP, Neon runs Postgres.

### S2. Architecture

The base is songrequest's module-first modular monolith, `src/<Module>/<Layer>/` with namespace `App\<Module>\<Layer>`. From DEP we take the class-modifier rules, the `final readonly` value objects and the read-model idea. Pupil's Port/In/Out layering, the 900-line deptrac config, query/event buses and RabbitMQ are overkill here.

| Module | Holds | May depend on |
|---|---|---|
| `Shared` | `EntityId`, `DomainException`, translation helpers, Swiss formatting Twig extension, HTTP cache helpers, legal pages, health | — |
| `Geo` | cantons, towns, postcode/town lookup, distances | — |
| `Media` | image variant generation, `<picture>` Twig helper | — |
| `Venues` | venue aggregate (translations, terms, rooms, photos, documents, reviews), detail page, import | Geo, Media |
| `Search` | `SearchCriteria`, listing read model, counts, ordering, similar venues, autocomplete endpoints, listing page | Venues, Geo, Media |
| `Favorites` | Merkliste cookie codec, toggle endpoint, `/merkliste` page | Search, Venues |
| `Inquiries` | inquiry form, entity, mails | Venues |
| `Seo` | sitemap, robots, JSON-LD builders, landing page definitions | Search, Venues, Geo |

- **Layers:** `Controller`, `Command`, `CommandHandler`, `Entity`, `Repository`, `ReadModel` (DBAL queries returning `final readonly` view objects), `ValueObject`, `Exception`, `Form`, `Console`, `Twig`, `Mailer`, `EventListener`.
- **Reads:** public pages go controller → `ReadModel` with hand-written SQL, and never hydrate entities for rendering. **Writes:** controller or console command → command → `command.bus` → handler. There is no query bus.
- **Enforcement:** phpat inside PHPStan **level 8** (greenfield), PHP-CS-Fixer `@Symfony` + `@Symfony:risky`. Test suites are `Unit`, `Integration`, `Application` and `Architecture`, with Foundry fixtures. The validation strategy is songrequest's three layers (form → handler → DB constraint). Migrations are hand-written SQL only, each with a `down()`.

### S3. Frontend

- **Templates:** Twig includes and embeds under `templates/components/`; #1 may adopt `symfony/ux-twig-component`, which has no client JS, if it reads cleaner.
- **CSS:** Sass partials compiled to one `app.css` by `symfonycasts/sass-bundle` (dart-sass binary, no Node). There is one partial per component, so parallel cycles each add their own file. Design tokens are CSS custom properties on `:root`.
- **JS:** vanilla ES modules, one per behaviour (`carousel.js`, `overlay.js`, `live-count.js`, `autocomplete.js`, `merkliste.js`, `share.js`). Each loads only on the pages that need it. There is no importmap of third-party packages.
- **Progressive enhancement:** filter overlays are HTML `popover` elements opened by `popovertarget`, so they work without JS. Filters are plain GET forms. Carousels are CSS scroll-snap containers that JS only adds arrows and counters to. Without JS, the only thing lost is the live "N Resultate anzeigen" count.
- **Icons:** an inline SVG sprite built from Lucide (ISC licence). The five Lage icons and the nine rating-highlight icons are drawn in the same line style; we do not copy eventlokale's SVGs.
- **Font:** Sofia Pro is a commercial font licensed to eventlokale, so we do not copy it. We use **Sofia Sans** (OFL, its free sibling), self-hosted as latin-subset woff2 in weights 400 and 600 (700 only if #1 proves it is needed), with `font-display: swap` and the 400 weight preloaded.
- **Colour tokens,** taken from eventlokale's CSS on 2026-10-30 (#1 samples the overlay colours exactly from `docs/reference/`):

  | Token | Value | Used for |
  |---|---|---|
  | `--c-accent` | `#ff5a75` | primary button, active chip, canton map fill, guest bubbles |
  | `--c-accent-strong` | `#ec536c` / `#d94d64` | hover, selected canton (darker, see `filter-region-selected.png`) |
  | `--c-bg` | `#121212` | page background below the hero |
  | `--c-surface` | `#2c2c2c` | header band, filter bar, cards |
  | `--c-surface-2` | `#464646`-ish (sample) | card body in `listing-card.png` |
  | `--c-line` | `#d9d9d9` / `#c5c5c5` | dividers, muted text on dark |
  | `--c-overlay-region` | teal-grey (sample from `filter-region.jpg`, CSS candidate `#6f889b`) | region overlay |
  | `--c-overlay-guests` | `#756a96` | guests overlay |
  | `--c-overlay-more` | warm grey (sample from `filter-more.jpg`, CSS candidate `#a4937d`) | weitere Filter overlay |
  | `--c-ink` | `#ffffff` | text on dark |

- **Contrast:** white on `#ff5a75` is 3.01:1, which fails WCAG AA for normal text, and white on the warm-grey overlay is about 2.98:1. #1 keeps the look but meets AA. Text-bearing pink buttons use `#121212` text (6.22:1) or a darker accent for the background (`#c8364f` gives 5.13:1). Body text on the overlays gets a darker overlay tone or a scrim. #1 records every deviation from the original.

### S4. Internationalisation (German now; FR, IT and EN later without rework)

- `default_locale: de`, `enabled_locales: [de]`, ICU YAML catalogues. There is **one translation domain per module** (`venues+intl-icu.de.yaml`, `search+intl-icu.de.yaml`, …), and `messages` holds layout strings only. Keys follow `<context>.<description>` inside the domain. No user-facing string appears in Twig or PHP outside a catalogue.
- **Fixed vocabularies** are PHP enums or code-keyed rows, and their labels come from catalogues. A later language therefore only adds catalogue files. This covers cantons, taxonomy terms, tiers/badges, rating labels and highlights, and FAQ answers.
- **Venue-authored text** lives in `venue_translations (venue_id, locale, slug, summary, description, meta_description)`, plus `room_translations` and `document_translations`, all with a fallback to `de`. The venue name is a proper noun and stays on `venues`.
- **Town names:** the official name comes from the dataset. A later `town_names (town_id, locale, name, slug)` table adds exonyms such as Genf/Genève.
- **Routes:** German paths are unprefixed. Every public route is declared in Symfony's localized form (`path: ['de' => '/hochzeitslocations']`) from day one. Later locales add `'fr' => '/fr/lieux-de-mariage'` and so on, with no route renamed. #2 adds a test that fails on a public route without the localized form.
- **Query parameters** use stable, language-neutral codes (`?canton=zh&guests=120&setting=lake`), so a URL shared in one language works in another. Path segments of landing pages are localized slugs.
- Swiss formatting (`1'500`, `30.10.2026`) comes through a `SwissFormatExtension` like songrequest's.

### S5. URLs

- `/` → 302 to `/hochzeitslocations` (until a home page exists; it is a 302 so it can change).
- `/hochzeitslocations` is the listing; `?page=2` paginates. There are **no seeds in URLs and no redirects** on listing requests.
- `/hochzeitslocations/{canton-slug}` (e.g. `/hochzeitslocations/zuerich`) are canton landing pages. #17 decides on town and building-type pages under the same prefix.
- `/hochzeitslocation/{venue-slug}` is the detail page. Slugs are ASCII-transliterated German (`ü` → `ue`). An old slug redirects with a 301 through `venue_slug_history`.
- `/merkliste` and `POST /merkliste/{number}` serve the Merkliste.
- `POST /hochzeitslocation/{venue-slug}/anfrage` receives inquiries.
- `/_search/count` and `/_search/places` are fragment/JSON endpoints, `noindex` and disallowed in `robots.txt`.
- `/sitemap.xml`, `/robots.txt`, `/impressum` and `/datenschutz` complete the set.

### S6. Ranking (paid attributes)

- `venues.tier` is an enum with ranks: `beliebte_location` 1, `empfehlung` 2, `top_premium` 3, `premium` 4, `basic` 5 (Q7). The badge next to the name is the tier label; `basic` shows none.
- Within a tier, venues are ordered by `md5(venue_id || seed)`, where the seed is today's date in Europe/Zurich. Every visitor sees the same order for a day, paging is stable, and pages are cacheable.
- The tier is set in the content file (#9) until a CMS exists.

### S7. Merkliste without login

- **Cookie:** `merkliste` holds comma-separated venue **numbers**. `venues.number` is an `INT GENERATED ALWAYS AS IDENTITY UNIQUE` public id, which keeps the cookie small. It is capped at 100 entries, `Max-Age` 400 days, `Secure`, `SameSite=Lax`, and *not* `HttpOnly`, so JS can read it.
- **Caching:** public pages never read the cookie, so they stay cacheable. After load, JS marks the hearts and the header count.
- **Without JS:** each Merken button is a small POST form to `/merkliste/{number}`. It toggles the cookie server-side and answers 303 back to the `Referer`, or to `/merkliste`.
- **`/merkliste` page:** it reads the cookie server-side and sends `Cache-Control: private, no-store` and `noindex`.
- **Privacy:** this is a functional cookie, described in the Datenschutz page. There is no consent banner, which is the owner's decision from the earlier attempt.

### S8. Content without a CMS

- **Real content** lives in files: one YAML per venue plus a photo folder, kept outside `public/` (default `content/`, configurable via `CONTENT_ROOT`). `app:venues:import` (#9) imports it idempotently.
- **Dev and test** use Foundry: a `DemoStory` with about 60 fictional venues across all 26 cantons and all tiers, with generated placeholder photos (#4/#5).
- **Production content** is the legacy import (#21, Q4). It turns the Laravel project's ~758 venues into content folders in `CONTENT_ROOT`, and #9 imports them like any other content. New venues later go through the same folder format until the CMS exists.
- No eventlokale content goes into the repo; see **Important**.

### S9. Images

- Originals are kept outside `public/` (`MEDIA_ROOT`).
- Derivatives are generated **at import time, never on request.** They are written as `public/media/<venue-number>/<photo-id>-<width>.<avif|webp|jpg>` at widths 360/640/960/1280/1920. Each photo row stores width, height and dominant colour, which becomes the placeholder background.
- Pages use `<picture>` with AVIF, then WebP, then JPEG, an explicit `width`/`height`, `loading="lazy"` everywhere except the LCP image, and `fetchpriority="high"` on the LCP image.

### S10. Caching

- Public GET pages start no session and set no cookies; an application test asserts this.
- They send `Cache-Control: public, s-maxage=600, stale-while-revalidate=86400` (#19 tunes the values).
- #19 picks the reverse proxy: Symfony `HttpCache`, which works on shared hosting, or Cloudflare. #9's import purges it.

### S11. Budgets and quality gates

- **PERF-1 budgets** (mobile Lighthouse, simulated slow 4G):
  - LCP ≤ 2.0 s, CLS ≤ 0.05, TBT ≤ 100 ms;
  - Lighthouse ≥ 95 in Performance, Accessibility, Best Practices and SEO;
  - listing HTML ≤ 60 KB gzip;
  - CSS ≤ 25 KB gzip in total;
  - JS ≤ 15 KB gzip per page;
  - fonts ≤ 2 files on first view;
  - uncached server time p95 ≤ 200 ms for the listing and ≤ 120 ms for the detail page, with the demo data ×10.
- **Every cycle:** `make cs-fix` → `make lint` → `make test`.
- **Every UI cycle also:**
  - the screenshot comparison from **Important**;
  - `make lighthouse URL=…` (added in #1) on the pages it touched;
  - a keyboard-only walkthrough;
  - the W3C validator on the rendered HTML.

## Requirements

IDs are referenced by the overview and the cycles.

**General**
- **GEN-1** German UI only for now, with every string in translation catalogues (S4).
- **GEN-2** Server-rendered Symfony + Twig. No TypeScript or JS framework or library; every page is usable without JS (S3).
- **GEN-3** Layout, style and colours copy eventlokale's wedding search (`docs/reference/`); brand design comes later.

**Listing page (LP)**
- **LP-1** `/hochzeitslocations` has a dark hero band with the h1 "Hochzeitslocations in der Schweiz" (canton pages: "… im Kanton Zürich"), "N Resultate", the filter bar, the result list, and pagination "1 - 48 von N Resultaten" with page links. eventlokale shows 48 per page; #7 keeps 48 unless PERF-1 fails, then 24. Unlike the original, the filter bar has no "Locations" and no event-type chip.
- **LP-2** Card as in `docs/reference/listing-card.png`:
  - photo on the left;
  - top row: capacity "50 - 450" (person icon), town "Ittigen" (pin icon), and "Merken ♡" on the right;
  - tier badge, then the venue name;
  - summary clamped to three lines (~150 characters, stored ≤ 200);
  - right column, divided by a rule, only when a rating exists: "Herausragend (9.0) | 474 👍" and up to three highlight quotes, each with an icon ("Feines Essen", "Stilvolle Atmosphäre", "Einzigartige Architektur").
  
  The card stacks on mobile, and the whole card links to the detail page.
- **LP-3** The card photo is a carousel (eventlokale has a single photo here; this is our addition). It holds up to 8 photos, browsed by swipe, arrow buttons or the keyboard without leaving the list. A counter shows "2 / 8". Only the first photo loads eagerly, and only for the first two cards.
- **LP-4** Results are ordered by paid tier, then by the daily seed (S6).
- **LP-5** "Andere suchten auch nach" link block below the results, linking canton and town landing pages (eventlokale links "Hochzeit Zürich", "Hochzeit Bern", …).
- **LP-6** Active filters show as chips in the filter bar with "×". The filter bar has Region, Anzahl Gäste, "weitere Filter +" and a round Merkliste button. Every overlay has "Filter löschen".

**Filters (FI)**
- **FI-1 Region** (`filter-region.jpg`, `filter-region-selected.png`):
  - full-screen teal-grey overlay with large decorative circles;
  - h2 "Wo soll eure Hochzeit stattfinden?" (#10 settles the wording; the original says "Wo soll dein Event stattfinden?");
  - tabs **Kanton** | **Ort/PLZ** and a large underlined input ("Kanton eingeben") with autocomplete;
  - on the right, an interactive SVG map of the 26 cantons with lakes, a pink fill and a drop shadow. Hover darkens a canton and shows a white tooltip with its name; click selects it, and it stays dark.
  - black "N Resultate anzeigen" button (live count), "Filter löschen" link, round "×" close.
  
  It must look and behave exactly like the original.
- **FI-2 Ort/PLZ:** autocomplete over towns and postcodes. Selecting one filters venues in that town or within a radius around it; #6 settles the radius, after checking eventlokale's `standort=` behaviour.
- **FI-3 Guests** (`filter-guests.jpg`): purple overlay with the h2 "Wieviele Gäste erwartest du?", an "Anzahl Gäste" number input, and pink bubbles 20/30/50/80/100/150/200/350/500 sized by value, which fill the input. It has the same footer buttons. #6 settles the capacity semantics.
- **FI-4 Weitere Filter** (`filter-more.jpg`): warm-grey overlay "Wähle weitere Filter & Möglichkeiten" with a sticky footer ("N Resultate anzeigen", "Filter löschen"). Its sections, in eventlokale's order:
  1. **Suche eine konkrete Location:** "Name eingeben".
  2. **Lage:** round icon buttons Am Fluss, Am See, Auf dem Land, In den Bergen, In der Stadt.
  3. **Erreichbarkeit:** Nähe Bahnhof, Nähe Autobahn, Nähe Flughafen.
  4. **Gebäudeart:** Bar, Club · Eventlocation · Hochzeitslocation · Hotel, Seminarhotel · Partyraum · Restaurant · Schloss · Kongresszentrum · Waldhütte · Bauernhof, Scheune · Berghütte, Alphütte, plus "Alle Gebäudearten", which expands to the full list.
  5. **Infrastruktur:** WLAN / Internet, Klimaanlage, Bühne, Dusche, Garderobe, Heizung & Lüftung, Küche zur Nutzung, Backstage Räume, Rednerpult.
  6. **Mobiliar:** Flipchart, Magnetwand, Moderatorenkoffer, Stühle, Tische.
  7. **Technik:** Tontechnik, Beamer / Leinwand, Dolmetscheranlage, Lichtanlage, TV / Screens.
  8. **Umgebung:** Garten, Spa & Wellness, Terrasse, Spielplatz, Grill, Golf.
  9. **Raumangebot:** dropdowns Anzahl Räume, Grösster Raum (m²), Min. Raumhöhe (m).
  
  These are copied 1:1 as asked, including the vocabulary that suits weddings poorly. #4 records the full Gebäudeart list; it is in eventlokale's public `/api/guest/location/all-taxonomies` (vocabulary `buildings`).
- **FI-5** Filters combine. #6 settles AND/OR within each group after checking eventlokale's counts.
- **FI-6** The whole filter state is in the query string (S4), so it is shareable, bookmarkable and works with the back button.

**Merkliste (MK)**
- **MK-1** "Merken ♡" on cards and "Location merken" on the detail page toggle the entry. The header heart shows the count.
- **MK-2** No login: a cookie (S7) that persists for 400 days.
- **MK-3** `/merkliste` lists saved venues as cards in saved order, each with a remove button, plus an empty state.
- **MK-4** A toast "{Name} wurde deiner Merkliste hinzugefügt." with a link to the Merkliste.

**Detail page (DP)**, as in `detail-page-top.jpg` and the live Stufenbau page:
- **DP-1** URL per S5; title "{Name} in {Ort} für die Hochzeit mieten | Hochzeitslocation" (#16 settles the wording).
- **DP-2** Full-width hero carousel with a "Bilder ansehen" button that opens a full-screen gallery (`<dialog>`, keyboard and swipe).
- **DP-3** Sticky anchor nav: Beschreibung, Bilder, Kontakt, Standort, Bewertung.
- **DP-4** Right-hand card "Anfragen & Merken", sticky on desktop:
  - pink "Verfügbarkeit prüfen", which jumps to the inquiry form;
  - outlined "Location merken" (MK);
  - "Teilen": Web Share API, with a fallback menu of copy link, WhatsApp and E-Mail;
  - "Drucken": `window.print()` with a print stylesheet; without JS the button is hidden.
  
  On mobile it becomes a bottom bar.
- **DP-5** Name with tier badge; "PLZ Ort"; an icon row with main building type, setting and "50 - 450 Personen"; the description.
- **DP-6 Bewertung:** "Herausragend (9.0) | 474" and "Was Besucher/innen und Veranstalter/innen an dieser Location besonders gefällt:" with three highlights. The labels are "Empfohlen" (7.7–7.9), "Ausgezeichnet" (8.0–8.9) and "Herausragend" (≥ 9.0); below 7.7 there is no label.
- **DP-7** Labelled lists: Gebäudeart, Lage, Geeignet für, Infrastruktur, Mobiliar, Technik, Umgebung.
- **DP-8 Räume:** a horizontal card carousel, each card with photo, name, capacity, "Rauminfos" (text dialog) and "Eckdaten" (dialog with area m², height m, floor material and capacities per seating type). #8 checks what eventlokale's dialogs show.
- **DP-9 Häufige Fragen:** Eigenes Catering? · Tageslicht? · Parking?
- **DP-10 Standort:** address, then distances with names ("0.9 km bis Bahnhof Papiermühle", "3.2 km bis Autobahn A6", "102 km bis Flughafen Basel-Mulhouse"), then a Google map (#15).
- **DP-11 Kontakt:** "Telefonnummer anzeigen" (click to reveal), E-Mail, website, contact person.
- **DP-12 Dokumente:** PDF links with titles.
- **DP-13** "{Name} kontaktieren" form:
  - three promises: Kostenlos · Unverbindlich · Direkt zur Location;
  - Anlass (default Hochzeit), Anzahl Gäste, Wunschtermin;
  - an editable message preview starting "Liebes {Name}-Team";
  - Vorname/Name, E-Mail, Telefon;
  - the "Anfragen" button, and a confirmation state.
- **DP-14** "Das sagen Besucher- und Veranstalterinnen": review quotes with first name, role and month, plus "Alle Kommentare".
- **DP-15** "Ähnliche Locations": 12 compact cards (photo, badge, name, town, capacity) plus "Alle Locations".
- **DP-16** The print stylesheet prints the facts, rooms, contact and address without navigation or forms.

**Inquiries (IQ)**
- **IQ-1** Inquiries are stored in `inquiries` with venue, full payload, created_at and a status for the mail outcome. The schema is designed so the later CMS/CRM can take them over unchanged (Q6).
- **IQ-2** In production, a mail goes directly to the venue's inquiry address (Q6), with the guest's address as `Reply-To` and a sender on `hochzeitslocation.ch`. The guest gets a confirmation copy. Both mails are German and in the site's look.
- **IQ-3** Spam protection without a CAPTCHA: honeypot field, minimum fill time, rate limiter per IP (Symfony RateLimiter), server-side validation.
- **IQ-4** `INQUIRY_RECIPIENT_OVERRIDE` redirects all venue mails in dev and staging (Mailpit), so no real venue is ever mailed from outside production. Production leaves it unset; a test asserts the production config does not set it.

**SEO, security, legal**
- **SEO-1** One h1 per page. Unique title and meta description patterns, canonical, Open Graph (`og:type` = `website` for the listing, `place` for venues) and Twitter tags.
- **SEO-2** JSON-LD: `EventVenue` + `LocalBusiness` on venues (address, geo, telephone, maximumAttendeeCapacity, image, **no** personal email), `BreadcrumbList`, `ItemList` on listings, `WebSite` + `Organization` on the root layout.
- **SEO-3** `/sitemap.xml`, paged if over 1,000 URLs, with `lastmod`; `/robots.txt` with https sitemap lines.
- **SEO-4** Indexable landing pages per canton, and per town / building type if #17 says so. Each has its own h1, title, intro sentence and links. Query-string filter combinations are `noindex,follow` with a canonical to the clean path.
- **SEO-5** Real status codes: a true 404 for missing venues, 410 for removed ones, 5xx for errors (never the 404 template), 301 for old slugs.
- **SEO-6** hreflang-ready: the helper exists and renders `de-CH` plus `x-default` now.
- **SEC-1** Security headers: CSP with nonces (no `unsafe-inline`), `frame-ancestors 'none'`, `X-Content-Type-Options`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy`, HSTS one year. Viewport without `user-scalable=no`.
- **LEG-1** `/impressum` and `/datenschutz` (inquiry data, Merkliste cookie, Google Maps, hosting, mail provider, Sentry), linked in the footer.
- **LEG-2** Inquiry data retention: #14 settles a deletion period (default 12 months) and #18 documents it.

**Accessibility (A11Y)**
- **A11Y-1** WCAG 2.2 AA:
  - skip link and zoom allowed;
  - overlays trap and return focus and close on Esc;
  - carousels are operable by keyboard and labelled;
  - visible focus;
  - contrast per S3;
  - the canton map is usable through the text input alone.

**Performance (PERF)**
- **PERF-1** Budgets and gates per S11.
- **PERF-2** Images per S9.
- **PERF-3** Public pages are cacheable (S10). The listing query uses indexes only (`EXPLAIN` in #6's research), with no N+1 (one query for cards, one for their photos, one for the count).

**Data (DATA)**
- **DATA-1** Content-file import (S8).
- **DATA-2** Demo data via Foundry (S8).
- **DATA-3** Legacy import from the Laravel project (Q4), the production content source.

**Operations (OP)**
- **OP-1** Docker dev stack, Makefile, CI (lint + test) on GitHub Actions.
- **OP-2** Production deploy with HTTPS, a manual deploy workflow gated on green CI, and a health check.
- **OP-3** Error monitoring (Sentry, EU region, like songrequest) and database backups.

## Reference material

- **Original pages:** listing https://www.eventlokale.ch/locations/hochzeit/seedId=33514164, detail https://www.eventlokale.ch/site/_eventlokale/1/Schweiz/1093/stufenbau_eventlocation.html.
- **Screenshots** in `docs/reference/`:
  - `listing-page-top.jpg`, `listing-card.png`;
  - `filter-region.jpg`, `filter-region-selected.png`, `filter-guests.jpg`, `filter-more.jpg`;
  - `detail-page-top.jpg`.
- **Site audit:** https://claude.ai/artifact/PzKBEaWRr9m6ZKVAUL4fto, 2026-09-08. Mostly out of scope; useful for its SEO, performance and security findings, i.e. what *not* to copy.
- **Earlier Laravel attempt** in `../hochzeitslocation`:
  - research `.rpi-tracking/research/2026-09-10/eventlokale-clone-research.md` and `subagents/live-verification.md` (data shapes, title patterns, JSON-LD);
  - `docs/eventlokale-import.md` (source data findings: distances are km, `rooms[].floor` is a material, FAQ fields are free text);
  - `database/data/towns.csv`: 4,057 rows from swisstopo's Amtliches Ortschaftenverzeichnis + BFS population (official open data). #3 reuses it.
- **eventlokale vocabulary:** `GET https://www.eventlokale.ch/api/guest/location/all-taxonomies`, public and unauthenticated. It holds `locations` (Lage), `buildings`, `infrastructures`, `technologies`, `furniture`, `surroundings`, `suitable_for`, and `sla_rating` (the nine rating categories behind the highlights).
- **Stack template:** `../songrequest` (`CLAUDE.md`, `.claude/rules/{php-architecture,cqrs,database,validation,i18n,code-quality}.md`, `tests/Architecture/*`, `Makefile`, `compose.yaml`, `.github/workflows/ci.yaml`, `src/Shared/*`).
- **Architecture references:**
  - `~/dev/domain-engineering-platform/apps/rooms` (phpat rules in `tests/Architecture/`, `.claude/rules/php-architecture.md`, `database.md`, `src/Uid/EntityId.php`);
  - `apps/attendances/src/ReadModel/` (read model pattern);
  - `~/dev/pupil-monorepo/apps/core/.claude/rules/{twig-templates,translations,value-objects}.md`.

## W0 Foundation

### 0. `project-skeleton`

- **Repository:** `git init`, a GitHub repo (the owner creates it, private), branch `main`. Copy and trim songrequest's skeleton:
  - `compose.yaml` (`php`, `nginx`, `database` postgres:18-alpine, `mailpit`) with the host-port variables from **Running cycles in parallel**;
  - `docker/` (PHP 8.5 image with `pdo_pgsql`, `intl`, `gd` with AVIF/WebP/JPEG, `exif`, `opcache`);
  - the `Makefile` targets: `init up down logs sh sf vendor db-migrate db-fresh seed test test-short cs-fix cs-check phpstan lint`.
- **Symfony 7.4 webapp minimal:** Twig, Doctrine, migrations, Mailer, Validator, Form, Foundry (dev/test), PHPUnit, PHP-CS-Fixer, PHPStan level 8 + phpat.
- **Modules:** `src/` holds the module folders from S2, each with a `.gitkeep`. `tests/Architecture/` copies songrequest's tests, with **all** S2 modules and their allowed dependencies declared now. `ReadModel` and `ValueObject` are added as layers.
- **Shared building blocks:** `Shared\Uid\EntityId`, `Shared\Exception\DomainException`, `Shared\Controller\HealthController` (`GET /health`), and the `FlexibleDateTimeTzImmutableType`, all from songrequest. The Postgres extensions (`pg_trgm`, `unaccent`, `cube`, `earthdistance`) come in the first migration.
- **Instructions:** `CLAUDE.md` adapted from songrequest, with this project's modules, commands, ports and a pointer to this file. Copy `.claude/rules/*.md` from songrequest and adjust (drop Billing/Tips/Stripe; add a `ReadModel` rule: public pages read through DBAL view objects).
- **CI:** `.github/workflows/ci.yaml` runs `make vendor`, `make lint`, `make test` against the Compose services, like songrequest.
- **Placeholder:** `/` answers with a plain "Hochzeitslocation" page until #7 replaces it with the redirect from S5.
- **Done when:**
  - `make init` works on a clean checkout;
  - `make lint` and `make test` are green locally and in CI;
  - an architecture test proves a forbidden module dependency fails;
  - the same stack starts in a second worktree on lane A's ports alongside the main checkout.

**Outcome (2026-10-30).** Done in commit `project-skeleton`. Copied and trimmed from songrequest `1c0fa4cd`.

- **Stack:** Symfony 7.4.22, PHP 8.5.12 in the image (Composer `platform.php` 8.5.11, `require.php >=8.5`), PostgreSQL 18, doctrine/orm 3.7.5, PHPStan 2.3.4 + phpat 0.12.5, PHPUnit 12.5.42, Foundry 2.15.0. The PHP image (`docker/php/Dockerfile`) has `pdo_pgsql`, `intl`, `gd` with AVIF/WebP/JPEG, `exif`, `zip`, OPcache and Xdebug; no `pcntl`.
- **Compose:** `compose.yaml` has `php`, `nginx` (nginx:stable-alpine), `database` (postgres:18-alpine, db/user/password `app`, volume `database_data`) and `mailpit`. Host ports come from `${NGINX_HOST_PORT:-8080}`, `${DB_HOST_PORT:-5432}`, `${MAILPIT_HOST_PORT:-8025}`. **A worktree selects its lane** by exporting the three variables in its shell before any `make` call (e.g. lane A: `NGINX_HOST_PORT=8081 DB_HOST_PORT=5433 MAILPIT_HOST_PORT=8026 make init`), or by a git-ignored `compose.override.yaml` that sets the `ports:`. The Compose project name is the directory name, so each worktree has its own containers, volume and `app_test`. Proved with a clean copy on lane A running beside 8080.
- **Makefile:** `init up down logs sh sf vendor db-migrate db-fresh seed test test-short cs-fix cs-check phpstan lint`, plus `build`, `cache-clear`, `db-create`, `db-test`, `audit`. `make sf c="debug:router"` runs a console command. `make lint` = `cs-check` + `phpstan` (the CI gate); `make test` prepares `app_test` first. `make seed` runs `foundry:load-fixtures main --append`, i.e. the story `App\Story\AppStory` (`#[AsFixture(name: 'main')]`, empty for now; #4 fills it or adds a `DemoStory` it calls).
- **Modules:** `src/{Geo,Media,Venues,Search,Favorites,Inquiries,Seo}/.gitkeep` plus `src/Shared/`. The recipe's layer-first `src/Controller`, `src/Entity`, `src/Repository` are gone. `config/services.yaml` excludes `src/*/Entity/`; there is no global Doctrine mapping, so each module adds its own `orm.mappings` entry with its first entity.
- **Shared** (all verbatim from songrequest): `App\Shared\Uid\EntityId`, `App\Shared\Exception\DomainException`, `App\Shared\Controller\HealthController` (`health`, `GET /health` → `{"status":"ok"}`), `App\Shared\Doctrine\Type\FlexibleDateTimeTzImmutableType` (registered in `doctrine.yaml`), `App\Shared\Messenger\CommandHandlerInterface` (marker; `_instanceof` tags handlers for `command.bus`). Messenger has exactly one bus, `command.bus`, **sync only**, with `doctrine_transaction`; no transports.
- **Routes:** `home.de` `GET /` → `App\Shared\Controller\HomeController`, template `templates/site/placeholder.html.twig` (`<h1>Hochzeitslocation</h1>`); #7 deletes both and adds the S5 redirect. `health` `GET /health`. No translation config or localized routes yet (#2).
- **Migrations:** `migrations/`, namespace `DoctrineMigrations` (songrequest's `doctrine_migrations.yaml`, not the recipe's `src/Migrations`). First migration `Version20261030100506` installs `pg_trgm`, `unaccent`, `cube`, `earthdistance`, with `down()`.
- **phpat:** rules run inside `make phpstan` (level 8), registered as `phpat.test` services in `phpstan.neon.dist`. Selectors live in `App\Tests\Architecture\ArchitectureRules` (`MODULES = 'Shared|Geo|Media|Venues|Search|Favorites|Inquiries|Seo'`, `layer('X')`, `modules()`). Rule classes: `ClassModifiersTest` (final/readonly per layer; `ReadModel` final, `ValueObject` final + readonly), `LayerIsolationTest`, `InheritanceContractsTest`, `InfrastructureAccessTest` (no EntityManager in controllers/console; `ReadModel` may not use entities or the EntityManager), `CqrsContractsTest`, `ModuleBoundariesTest` (8 rules, one per module, the S2 matrix). **A later cycle adds a layer** by using `self::layer('NewLayer')` in a new `test_*` method of the matching rule class (or a new class extending `ArchitectureRules`, registered in `phpstan.neon.dist`). It never edits `MODULES` or `ModuleBoundariesTest`; a needed change there is a design change for this file. `ModuleBoundariesProbeTest` (suite `Architecture`, fixtures in `tests/Architecture/Fixtures/`, config `phpstan-module-boundaries.neon`) proves Geo → Venues fails PHPStan.
- **Tests:** suites `Unit`, `Integration`, `Application`, `Architecture` (18 tests). Includes `PostgresExtensionsMigrationTest` (unaccent, trigram similarity, Zürich–Bern distance).
- **CI:** `.github/workflows/ci.yaml` ("CI", on push to `main` and PRs): `docker compose up -d --build --wait php database`, `make vendor`, `make lint`, `make test`. Also `.pre-commit-config.yaml`.
- **Instructions:** `CLAUDE.md` (`## Modules` has one `###` per module; cycles append to theirs), `.claude/rules/{php-architecture,cqrs,database,validation,i18n,code-quality,read-model}.md`, skill `.claude/skills/fix-phpstan`. `.gitignore` ignores `/.idea/`, `/.rpi-tracking/`, `/compose.override.yaml`.
- **Deviations:** migrations moved from the recipe's `src/Migrations` to `migrations/`; `tests/bootstrap.php` is songrequest's (no `method_exists` guard, PHPStan level 8); `mailer.yaml` left as the recipe wrote it (sender is #14/#20); recipe files `csrf.yaml` (stateless CSRF), `bin/phpunit`, `assets/app.js`, `assets/styles/app.css`, `templates/base.html.twig` kept as written (the last three are #1's).
- **Carried forward:**
  - F-m1: the two `because()` hints in `tests/Architecture/InfrastructureAccessTest.php` still say "reads go through repositories"; they should point at the module's `ReadModel`.
  - F-m2: `.claude/rules/read-model.md:14` says `InfrastructureAccessTest` forbids repositories and writes; the rule forbids only entities and the EntityManager. #6/#7 either add `self::layer('Repository')` to that rule or reword the sentence.
  - The probe fixtures (`App\Geo\Probe\*`, `App\Venues\Probe\*` under `tests/Architecture/Fixtures/`) do not follow PSR-4, so `composer dump-autoload -o` prints three "Skipping" warnings. Harmless; `autoload-dev.exclude-from-classmap` would silence them.
  - #1: `templates/base.html.twig` still injects the es-module-shims polyfill from `ga.jspm.io` via `importmap('app')`, and `assets/app.js` has a `console.log`. Drop both (`framework.asset_mapper.importmap_polyfill: false`).
  - `tests/Architecture/LayerIsolationTest.php` hints still mention a "worker" and "async transports" (songrequest leftovers; there are none).

## W1 Design, languages, geography (parallel)

### 1. `design-system`

- **Tokens** (S3): colours (sample the overlay colours from `docs/reference/` and record the hex values here in the Outcome), type scale (h1 ≈ 44/52 px desktop, card name ≈ 34 px, body 17 px, small 14 px, as measured from the screenshots), spacing, radii (the original is almost square: 2–4 px), shadows (the canton map's drop shadow, bubble shadows).
- **Fonts:** Sofia Sans woff2 subset self-hosted under `assets/fonts/` with `@font-face` and a preload.
- **Sass setup** with `symfonycasts/sass-bundle`: `assets/styles/app.scss` with `@use` lines for `tokens`, `base`, `layout`, and one partial per component.
- **Base layout** `templates/base.html.twig`:
  - `<html lang>` from the request locale;
  - blocks `title`, `meta`, `canonical`, `hreflang`, `jsonld`, `body_class`, `hero`, `main`, `scripts`;
  - a skip link;
  - header as in the original: hamburger left, centred wordmark, search / heart (with count badge) right; no user icon, since there are no accounts;
  - a slim footer: a link-list slot for "Hochzeitslocations nach Kanton" (filled by #17), the Impressum/Datenschutz slot (#18), ©.
- **Components** (Twig + Sass partial each):
  - button: primary pink, black, outlined, round icon button like "Karte"/"Merkliste";
  - chip with "×"; tier badge (grey rounded tag);
  - icon (`<svg><use>` from the sprite);
  - overlay shell (`popover`, full-screen, coloured background with the two large decorative circles, round "×", sticky footer slot);
  - dialog shell (`<dialog>`);
  - horizontal scroll-snap carousel shell plus `assets/js/carousel.js` (arrows, counter, keyboard; under 2 KB);
  - toast;
  - underlined large text input as in the overlays.
- **Icon sprite:** from Lucide (person, pin, heart, heart-filled, search, menu, x, chevrons, share, printer, calendar, phone, mail, globe, file, train, car, plane, info, maximize, thumbs-up) plus hand-drawn Lage and highlight icons in the same stroke width. Record the licence in `assets/icons/LICENSES.md`.
- **Styleguide:** `/_styleguide` (dev env only) renders every component. UI cycles check against it.
- **Tooling:** `make lighthouse URL=…` (Lighthouse CLI in a throwaway Node container; tooling only, not runtime) writes JSON + HTML reports to `var/lighthouse/`.
- **Contrast** per S3: list every deviation from the original's colours.
- **Done when:**
  - the styleguide matches the reference screenshots' look at 390 and 1440 px;
  - Lighthouse on the styleguide scores ≥ 95 in Accessibility and Best Practices;
  - CSS ≤ 25 KB gzip;
  - no inline styles and no inline scripts (CSP-ready for #16).

### 2. `i18n-foundation`

- Implement S4:
  - `translation.yaml` (`default_locale: de`, `enabled_locales: [de]`, fallback `de`);
  - one ICU catalogue per module domain (empty stubs for modules not yet built);
  - `Shared\Translation\EnabledLocale` (from songrequest);
  - `SwissFormatExtension` (`swiss_number`, `swiss_date`; capacity ranges render "50 - 450" as in the original).
- **Localized routes:** the convention, a helper for the `path: {de: …}` form, and an Architecture/Application test that fails when a public route is not localized.
- **Content translations:** a reusable pattern for translation rows. A `Translatable` trait or abstract mapped superclass, or plain repository joins; research picks one. The rules are: a locale fallback to `de`, a `localized()` read in the read models, and a unique `(entity_id, locale)` key.
- **hreflang:** a helper that renders alternates for every enabled locale plus `x-default` (only `de-CH` now) into the `hreflang` block #1 provides.
- **Translation checks:**
  - `make lint` runs `lint:translations` (or `debug:translation --only-missing` in a test), so a missing key fails CI;
  - a Twig lint rule or test catches visible text outside `|trans` in templates. Research checks feasibility; at minimum a review checklist item.
- **Done when:**
  - a demo route renders German strings from two domains;
  - the localized-route test fails for a plain route and passes for a localized one;
  - adding a dummy `fr` catalogue plus `enabled_locales: [de, fr]` in a test kernel serves `/fr/...` without code changes (proven in a test, not shipped).

**Outcome (2026-10-30).** Done in commit `i18n-foundation`. The full guidance is in `.claude/rules/i18n.md`; read it before writing a route, a template or a translated table.

- **Package and config:** `symfony/translation` 7.4.22. `config/packages/translation.yaml`: `default_locale: de`, `enabled_locales: [de]`, translator `fallbacks: [de]`, `set_locale_from_accept_language: false` (the locale comes from the URL only, so no `Vary: Accept-Language`), `set_content_language_from_locale: true` (every response sends `Content-Language: de`). No migration, no env var.
- **`App\Shared\Translation\EnabledLocale`** (from songrequest): `all()`, `default()`, `isEnabled()`, `clamp()`. It is the only place in `src/` that reads `%kernel.enabled_locales%` / `%kernel.default_locale%`.
- **Localized routes:** every public route uses Symfony's localized form, `#[Route(path: ['de' => '/hochzeitslocations'], name: 'listing')]`. German paths are unprefixed; Symfony registers `listing.de`; templates call `path('listing')` without a suffix. Exempt: route names starting with `_` (`_styleguide`, `_profiler*`, `_wdt*`, `_preview_error`), paths starting with `/_` (`/_search/*`), and a route that opts out with `options: [LocalizedRoutes::UNLOCALIZED => true]` (`App\Shared\Translation\LocalizedRoutes`). `health` opts out this way. `tests/Application/Shared/Translation/PublicRoutesAreLocalizedTest.php` runs `LocalizedRoutes::violations()` over the app router. It fails for a plain route and for a localized route with no path for an enabled locale.
- **Swiss formats:** `App\Shared\Twig\SwissFormatExtension` has the filters `swiss_number` (`1'234'567.5`, `|swiss_number(2)` for fixed decimals) and `swiss_date` (`05.10.2026`), plus the **function** `swiss_range(min, max)` (`{{ swiss_range(50, 450) }}` renders "50 - 450"; equal bounds render one number). Never use `|date` or `number_format`. Pass numbers into messages pre-formatted with `swiss_number`, not through ICU `{n, number}`, because the translator locale is `de`, not `de_CH`.
- **hreflang:** the Twig function `hreflang_alternates(query = {})` (`App\Shared\Twig\HreflangExtension`, backed by `App\Shared\Translation\HreflangAlternates` → `HreflangAlternate{hreflang, href}`) returns one absolute `<locale>-CH` URL per enabled locale, plus `x-default` (today: `de-CH` and `x-default`). The partial `templates/shared/_hreflang.html.twig` renders them as `<link rel="alternate">`. **`base.html.twig` must include it inside its `{% block hreflang %}` as `{{ include('shared/_hreflang.html.twig', ignore_missing = true) }}`.** Whichever of #1 and #2 lands second adds that line. Pass `{query: {page: 2}}` for parameters that select a different page. Routes not in the localized form get no alternates.
- **Content translations:** a plain per-module entity (e.g. `Venues\Entity\VenueTranslation`, unidirectional `ManyToOne` to its owner). There is no shared trait and no mapped superclass. Table `<entity>_translations (id UUID PK, <entity>_id UUID NOT NULL FK, locale VARCHAR(2) NOT NULL, …fields, created_at, updated_at)` with `uk_<table>_<entity>_id_locale UNIQUE (<entity>_id, locale)`, `chk_<table>_locale CHECK (locale ~ '^[a-z]{2}$')` and an index on the FK. The write side validates `locale` with `EnabledLocale::isEnabled()`; every owner has a `de` row. Read models use `App\Shared\Translation\TranslationJoin::localized($table, $foreignKey, $ownerIdSql, $alias)`, which returns a `LEFT JOIN LATERAL` that takes the requested locale's row or else the `de` row (per row, so all fields come from one language). Bind it with `->parameters($locale)` (`:i18n_locale`, `:i18n_fallback_locale`). The venue name stays on `venues`. Fixed vocabularies are PHP enums implementing `TranslatableInterface`, keyed `<context>.<case value>`, and each such enum gets a test that every key exists.
- **Catalogues:** ICU, one domain per module, `translations/<domain>+intl-icu.de.yaml`. Empty stubs exist for `shared`, `media`, `venues`, `search`, `favorites`, `inquiries` and `seo`; add keys to them. `messages` (layout strings) is #1's file and `geo` is #3's; each cycle creates its own.
- **Checks:** `make lint-translations` (`lint:translations`, ICU syntax) is part of `make lint`. `make test` adds, under `tests/Integration/Shared/Translation/`:
  - `TranslationCompletenessTest`: `debug:translation --only-missing` per enabled locale, so a missing key fails;
  - `CatalogueParityTest`: every enabled locale has exactly `de`'s keys;
  - `TemplatesHaveNoRawTextTest`: raw text, letters in `alt`/`title`/`aria-label`/`placeholder`, or a printed literal outside `|trans` fails. The scanner is `tests/Support/Twig/RawTextScanner.php`, with its self-test `RawTextScannerTest`.
  - The raw-text test's `BASELINE` only shrinks (a stale entry fails): **#1 removes `'base.html.twig' => ['Welcome!']`** when it rewrites the layout, and **#7 removes `'site/placeholder.html.twig' => ['Hochzeitslocation']`**.
  - The scanner cannot see literals passed as variables to includes or macros, nor strings in PHP; those remain a review checklist item.
- **Probe kernels (test-only, not shipped):** `tests/Fixtures/I18n/` (`I18nProbeKernel`, `FrenchProbeKernel`, `ProbeController`, its own templates and catalogues). `I18nProbeTest` proves a German page with two domains, `50 - 450` and hreflang. `FrenchLocaleProbeTest` proves that `enabled_locales: [de, fr]` plus a `fr` catalogue serves `/fr/...` with no change to `src/` or `config/`. Helpers: `tests/Support/Translation/{CatalogueParity,KernelLocales}.php`.
- **Deviations:**
  - Research D7 was wrong. The router drops `_canonical_route` from the matched request and reports the canonical name in `_route` (`listing` for a match of `listing.de`). `HreflangAlternates` reads `_route` and requires `_route_params._locale`. Without this fix, hreflang would have rendered nothing on every page.
  - The raw-text scanner also detects printed literals from the token stream, because Twig's optimizer turns `{{ 'Text' }}` into a `TextNode`. It also keeps adjacent block bodies apart.
  - The probe kernels' `configureContainer()`/`configureRoutes()` are `protected`, not `private` (PHPStan `method.unused`).
  - `config/reference.php` changed by one regenerated line.
- **Carried forward:**
  - Review F-m1, for #16: once error templates extend `base.html.twig`, a 404/500 thrown inside a matched localized route would render hreflang links, because the extension reads the main request. Fix: return `[]` when the request has an `exception` attribute, or empty the `hreflang` block in the error templates. Then also correct the "error pages" sentence in `.claude/rules/i18n.md`.
  - `.claude/rules/i18n.md` says "Never read `%kernel.enabled_locales%` elsewhere". It should say "elsewhere in `src/`", since test helpers read it.
  - CLAUDE.md's commands-table row for `make lint` (`cs-check` + `phpstan`) does not list `lint-translations`. The `### Shared` paragraph documents it.

### 3. `geo-reference-data`

- **Tables:**
  - `cantons`: the 26 cantons; `code` (`zh`), `slug` (`zuerich`), `svg_id`, centroid; labels from the `geo` catalogue.
  - `towns`: `zip`, official `name`, `slug`, `canton_code`, `bfs_number`, `lat`, `lng`, `population`; GIN trigram index on `unaccent(name)` and a btree index on `zip`.
- **Import:** `app:geo:import-towns` loads `towns.csv`, copied from `../hochzeitslocation/database/data/towns.csv` with its source note: one row per postal district. Collapse it to one town per `(canton, name)` with the lowest ZIP while keeping all ZIPs searchable (a `town_zips` table), following that project's `collapseToOneTownPerName()` finding.
- **ReadModel:**
  - `PlaceLookup::suggest(string $q)`: cantons and towns by prefix, trigram and ZIP, ranked by population, at most 8 results;
  - `TownLocator::withinKm(townId, km)` on `earthdistance`.
- **Canton SVG:** an open-licensed source (e.g. swisstopo swissBOUNDARIES3D generalised and simplified, or a CC-licensed Wikimedia map), simplified to ≤ 40 KB, with lakes as a separate layer and one `<path id="ch-zh">` per canton. Commit it to `assets/maps/cantons.svg` with the licence noted.
- **Done when:**
  - all 26 cantons and about 3,960 towns are imported idempotently;
  - integration tests cover autocomplete ("zuer" → Zürich, "8712" → Stäfa, "rapperswil" → Rapperswil-Jona) and radius;
  - the SVG renders in the styleguide with every canton id present (test).

## W2 Data model

### 4. `venue-schema`

- **Schema** (hand-written migration), per S2/S4/S5/S6:
  - `venues`: `id` UUID, `number` identity, `slug`, `name`, `tier`, `status`, `capacity_min`, `capacity_max`, address fields, `town_id`, `canton_code`, `lat`, `lng`, distances (`distance_station_km`, `station_name`, `distance_highway_km`, `highway_name`, `distance_airport_km`, `airport_name`), contact fields (`phone`, `inquiry_email`, `website`, `contact_person`), FAQ (`own_catering` enum, `daylight` bool, `parking_text` → translated), rating (`rating_score` numeric(3,1), `rating_count`), `room_count`, `largest_room_m2`, `min_room_height_m`, `main_building_type`, timestamps;
  - `venue_translations`, `venue_slug_history`;
  - `taxonomy_terms` (`group`, `code`, `position`, `is_primary_filter`) and `venue_terms`;
  - `venue_rating_highlights` (code, position ≤ 3);
  - `venue_reviews` (first name, role, month, text, locale);
  - `rooms` (+ `room_translations`; `capacity_min/max`, `area_m2`, `height_m`, `floor_material`, capacities per seating type as columns or a small child table, chosen in research);
  - `venue_documents` (+ translations; path, title, size);
  - `venue_photos` (`venue_id`, `room_id` NULL, `position`, `alt` via translation, original path; #5 adds the variant columns).
- **Constraints:** CHECKs for capacity order, tier values, rating range 0–10 and `position` uniqueness; unique slug per locale.
- **Vocabulary** as code (enum or seeded rows plus catalogue labels):
  - Lage (5) and Erreichbarkeit (3, derived from distances; #6 settles the thresholds);
  - Gebäudeart (the full `buildings` list, with the 11 primary ones flagged as shown before "Alle Gebäudearten");
  - Infrastruktur (9), Mobiliar (5), Technik (5), Umgebung (6);
  - "Geeignet für": copy eventlokale's `suitable_for` list, as the owner asked to copy it; research may reorder it with wedding-relevant entries first;
  - the nine rating highlights, mapped to the `sla_rating` categories with icon codes: Feines Essen, Stilvolle Atmosphäre, Einzigartige Architektur, Einmalige Lage, Freundliche Gastgeber, Perfekte Erreichbarkeit, Moderne Ausstattung, Attraktives Programm, Top Preis/Leistung;
  - tiers;
  - rating labels (DP-6).
- **Entities and factories:** entities, repositories, Foundry factories, and `DemoStory` per S8: about 60 fictional venues, every canton and tier, realistic capacity spreads, 0–3 reviews, 1–5 rooms, 0–2 documents, some without a rating. `make seed` loads it.
- **Done when:**
  - migration up and down work;
  - integration tests prove every CHECK and unique constraint;
  - `DemoStory` loads in under 10 s;
  - this file's Outcome lists every vocabulary code.

## W3 Media and search (parallel)

### 5. `venue-media`

- `Media\ImageVariantGenerator` implements S9. Research picks gd vs Imagick vs `liip/imagine-bundle` prewarm, verifies AVIF encoding in our PHP image **and on the Q2 candidate hosts** (fallback: WebP + JPEG only), and measures encode time per photo.
- **Variants:** widths 360/640/960/1280/1920 per photo (no upscaling), EXIF orientation applied and metadata stripped (like songrequest's `PhotoResizer`), dominant colour stored, and the `venue_photos` columns `width`, `height`, `dominant_color`, `variants` (a JSONB list of generated widths and formats) added.
- **Twig:**
  - `{{ venue_picture(photo, sizes='(min-width: 900px) 520px, 100vw', loading='lazy', priority=false) }}` renders `<picture>` with an explicit size and the dominant colour as background;
  - `{{ document_url(doc) }}`.
- **Demo photos:** `DemoStory` gets generated placeholder photos, e.g. gradients plus a venue initial rendered by gd, varied aspect ratios, about 30 originals reused. They are written under `MEDIA_ROOT` and their variants generated.
- **Command:** `app:media:regenerate [--venue=]` re-renders the variants.
- **Done when:**
  - integration tests prove variants exist with correct dimensions and orientation;
  - an application test renders a `<picture>` with AVIF/WebP sources (or the documented fallback);
  - a card-size AVIF is ≤ 40 KB at 640 px for the demo images.

### 6. `search-query`

- **`Search\ReadModel\SearchCriteria`** (`final readonly`) with a URL codec `fromQuery(InputBag)` / `toQuery()`. The codec owns the parameter names (S4) and every filter from FI-1 to FI-4: `canton`, `place` (town id or ZIP) + `radius`, `guests`, `q` (name), `setting[]`, `access[]`, `building[]`, `infra[]`, `furniture[]`, `tech[]`, `surroundings[]`, `rooms`, `area`, `height`, `page`. Invalid values are dropped, never a 500.
- **Settle from evidence** by watching eventlokale's result counts while toggling filters (polite, ≤ 20 requests). Record each answer in the Outcome:
  - AND vs OR inside each group;
  - guest semantics: `capacity_max ≥ guests`, or also `capacity_min ≤ guests`;
  - Ort/PLZ radius;
  - Erreichbarkeit thresholds;
  - what "Grösster Raum" and "Min. Raumhöhe" offer as dropdown values.
- **`ListingQuery`** (DBAL):
  - `results(criteria, locale): ListingPage` returns cards with everything LP-2 needs and the first 8 photo variants per card, in **≤ 3 queries** (no N+1);
  - `count(criteria): int`;
  - ordering per S6.
- **`SimilarVenuesQuery`:** same canton first, then nearest by distance, capacity overlap, excluding the venue itself, 12 results.
- **Endpoints** (Search controllers, `noindex`, short cache):
  - `GET /_search/count?…` → `{"count": 931}` for the live overlay buttons;
  - `GET /_search/places?q=` → canton/town suggestions (JSON) for #10.
- **Performance:** with `DemoStory` ×10 (about 600 venues, a test-only story), `EXPLAIN ANALYZE` of the heaviest filter combination uses indexes and runs in < 20 ms; add whatever indexes this needs.
- **Done when:**
  - unit tests cover the codec (round trip, garbage input);
  - integration tests cover every filter, the ordering (tier first, stable for a day, changes with the date) and the counts;
  - the performance numbers are recorded in the Outcome.

## W4 Pages (parallel)

### 7. `listing-page`

- **Controller** `GET /hochzeitslocations` (localized route) in `Search`; `/` → 302 here (S5).
- **Hero band** per `listing-page-top.jpg`:
  - h1, "N Resultate";
  - filter bar `templates/search/_filter_bar.html.twig` with chips for active filters and three `{% include %}` slots for #10–#12. Until those land, each slot is a plain button that opens an empty overlay shell. The round "Merkliste" button links to `/merkliste`. There is **no** "Karte" button; the map view is a proposal.
- **Result list:** cards per LP-2 (a `templates/search/_card.html.twig` component that #13 and #8's similar list reuse), carousel per LP-3, the `venue_picture` priority rules, "Merken ♡" as the S7 no-JS form (#13 adds the JS).
- **Pagination** "1 - 48 von N Resultaten" with numbered links "1 2 3 … 20" (`?page=`); `rel=prev/next` is not needed. Page > last → 404.
- **Empty state:** "Keine Location passt zu deinen Filtern" + "Filter löschen".
- **"Andere suchten auch nach":** a placeholder block linking cantons with the most venues; #17 replaces its source.
- **Headers:** per S10, so no session and no `Set-Cookie`.
- **Done when:**
  - application tests cover rendering, pagination, filters from the query string (via #6), the empty state and the 404 beyond the last page;
  - the screenshot comparison passes;
  - Lighthouse (mobile) ≥ 95 in all four categories on `/hochzeitslocations` with demo data;
  - listing HTML ≤ 60 KB gzip.

### 8. `venue-detail-page`

- **Controller** `GET /hochzeitslocation/{slug}` in `Venues`, reading through a `VenueDetailQuery` read model; an old slug gives 301 (S5), unknown 404, unpublished 410.
- **Sections** in the original's order, DP-2 to DP-12 and DP-14 to DP-16:
  - hero carousel + "Bilder ansehen" gallery dialog;
  - sticky anchor nav;
  - sticky "Anfragen & Merken" card (Verfügbarkeit prüfen → `#anfrage`; the Merken form; Teilen via `share.js`; Drucken);
  - head block with badge, icon row and description;
  - Bewertung;
  - fact lists;
  - Räume carousel with "Rauminfos" / "Eckdaten" dialogs (check the original's dialog content first);
  - Häufige Fragen;
  - Standort text and distances, with an empty `{% block map %}` for #15;
  - Kontakt with phone reveal: the number is not in the HTML until clicked. It is fetched from `GET /hochzeitslocation/{slug}/telefon`, which is `noindex`, rate-limited and also works without JS as a normal link;
  - Dokumente;
  - reviews with "Alle Kommentare" (`<details>`);
  - an anchor `#anfrage` with an empty `{% block inquiry %}` for #14;
  - Ähnliche Locations (12, via `SimilarVenuesQuery`) + "Alle Locations".
- **Print stylesheet** (DP-16).
- **Done when:**
  - application tests cover 200/301/404/410, all sections rendering with a full demo venue, and missing optional data (no rating, no rooms, no documents) hiding its section instead of leaving empty headings;
  - the screenshot comparison with `detail-page-top.jpg` and the live page's lower sections passes;
  - Lighthouse ≥ 95.

### 9. `venue-content-import`

- **Content format** (S8): `content/venues/<key>/venue.yaml` plus `photos/`, `rooms/<room-key>/photos/` and `documents/`.
  - `venue.yaml` holds every field from #4, including tier and translations keyed by locale.
  - A JSON Schema in `docs/content-format.schema.json` and an annotated example in `docs/content-format.md` are written for the owner.
- **`app:venues:import [path] [--dry-run] [--only=key]`:**
  - validates against the schema and the vocabulary codes (an unknown code fails that venue loudly);
  - upserts by `key` and computes the slug, keeping history when the name changes;
  - resolves the town by ZIP + name through #3, failing on ambiguity;
  - copies originals to `MEDIA_ROOT` and generates variants (#5) only for new or changed photos (content hash);
  - unpublishes venues missing from the content set only with `--prune`;
  - records a `content_imports` row (counts, errors);
  - purges the HTTP cache (an interface now; #19 implements it).
- **Done when:**
  - integration tests cover a fixture content folder for create, update, rename (301 works), photo change, invalid vocabulary and `--dry-run`;
  - running the import twice changes nothing.

## W5 Features (parallel)

### 10. `filter-region`

- **Overlay** per FI-1, in the slot from #7. Copy the screenshots' layout exactly:
  - left column: title, tabs, input, buttons;
  - right: the map;
  - decorative circles;
  - on mobile the map goes below the input or is hidden. Research checks what the original does at 390 px.
- **Kanton tab:** the input autocompletes canton names (client-side from the 26 names in the HTML, no request needed); the map is the inline SVG from #3.
  - Hover/focus darkens a canton and shows the tooltip.
  - Click or Enter selects it (one canton, as the original does; research confirms single vs multi) and updates the input and the live count.
  - Each `<path>` is focusable with an `aria-label`.
  - Without JS, a `<select>` of cantons is the fallback inside `<noscript>`, or the input submits by name.
- **Ort/PLZ tab:** the input calls `/_search/places` (debounced at 150 ms, aborting stale requests). Choosing a town sets `place` (+ radius per #6) and updates the count.
- **Shared JS:** `live-count.js` reads the overlay form, calls `/_search/count`, and updates "N Resultate anzeigen". #11 and #12 use the same module. "Filter löschen" clears this overlay's fields only.
- **Done when:**
  - application tests cover canton, place and radius filtering through the query string;
  - the screenshot comparison with both region screenshots passes (default and Zürich selected/hover);
  - keyboard-only selection works;
  - the JS for the overlay plus the map is ≤ 6 KB gzip.

### 11. `filter-guests`

- **Overlay** per FI-3: number input; bubbles as buttons, absolutely positioned like the screenshot, with sizes scaled by value, that fill the input.
- **Live count** via `live-count.js`; chip label "120 Gäste".
- **Mobile:** research checks the original at 390 px.
- **Done when:**
  - an application test covers the guests filter;
  - the screenshot comparison passes;
  - keyboard-only works.

### 12. `filter-more`

- **Overlay** per FI-4, all nine sections in order.
  - Lage: round icon buttons (checkboxes styled as tiles).
  - Erreichbarkeit, Gebäudeart, Infrastruktur, Mobiliar, Technik, Umgebung: rectangular toggle chips as in the screenshot. "Alle Gebäudearten" expands the full list (`<details>`).
  - "Name eingeben" (`q`).
  - Raumangebot: three `<select>`s with the values #6 recorded.
- **Sticky footer** with the live count.
- **Chips** in the filter bar summarise the selections: one chip per value, or "Weitere Filter (3)" if there are more than three. Research checks what the original shows after applying several and copies it.
- **Done when:**
  - an application test covers each group;
  - the screenshot comparison passes for the top and the scrolled bottom;
  - keyboard-only works.

### 13. `merkliste`

- **S7 in full:**
  - `Favorites\MerklisteCookie` codec (parse, cap at 100, ignore garbage, dedupe);
  - `POST /merkliste/{number}` toggle with a 303 back;
  - `assets/js/merkliste.js`, which reads the cookie, sets `aria-pressed` and the filled heart on every Merken button, intercepts submits to toggle client-side (writing the same cookie format), updates the header count, and shows the toast (MK-4) "{Name} wurde deiner Merkliste hinzugefügt." with a link.
- **`/merkliste` page:**
  - h1 "Deine Merkliste" and "N Locations";
  - cards via the #7 card component, in saved order, each with "Entfernen";
  - empty state with a link to the listing;
  - `private, no-store`, `noindex`.
- **Detail page:** "Location merken" on #8's card uses the same behaviour.
- **Done when:**
  - unit tests cover the codec;
  - application tests cover the no-JS toggle, the page with valid, stale and garbage cookie values, and that listing/detail responses never vary on the cookie;
  - a manual JS check is recorded (toggle on a card → the detail page shows it saved → `/merkliste` lists it).

### 14. `inquiry-form`

- **Form** in `Inquiries` per DP-13, rendered into #8's `{% block inquiry %}` (an embedded controller or a Twig function; research picks, keeping the detail page cacheable, so the CSRF token must not break caching). The candidates: no CSRF token but a honeypot, time trap and rate limiter as IQ-3, or a token fetched via a small request. Research decides; the default is no session on the page.
- **`SubmitInquiry` command + handler:**
  - stores the inquiry (IQ-1), with enough of the venue's state copied in (name, recipient address used) that the record stays meaningful after the venue changes, for the later CRM (Q6);
  - sends the venue mail directly to the venue (Q6, IQ-2) and the guest confirmation via Mailer (Twig HTML mails; the text part derived like songrequest's `EmailHtmlToTextConverter`), respecting `INQUIRY_RECIPIENT_OVERRIDE` outside production (IQ-4);
  - a venue without an inquiry address shows no form, only the Kontakt block. Research checks how many legacy venues (#21) lack one;
  - delivers synchronously, or via Messenger with songrequest's drain-on-terminate if research shows slow SMTP;
  - the guest sees a confirmation (303 to `#anfrage` with a signed success flag, as songrequest's sent page does).
- **Validation:** form (shape) → handler (venue published, guests within 1–5,000, date not in the past) → DB constraints.
- **Retention:** `app:inquiries:purge` deletes inquiries older than 12 months (LEG-2), run by cron.
- **Done when:**
  - application tests cover success, 422 with values kept, honeypot, too-fast submission and the rate limit;
  - Mailpit receives both mails;
  - the override is honoured.

### 15. `venue-map`

- **Standort map:** a static, keyless facade first (a styled placeholder with the address and an "Karte laden" button). Clicking it swaps in a **Maps Embed API** iframe (`loading="lazy"`, key from `GOOGLE_MAPS_EMBED_KEY`). With no key set (dev, or Q3 open), the button is an "Auf Google Maps öffnen" link to `https://www.google.com/maps/search/?api=1&query=…`.
- No Maps JavaScript API, so no client-side map JS from us.
- **CSP** (#16) allows the Google frame origin; Datenschutz (#18) mentions Google Maps.
- **Done when:**
  - application tests cover both modes;
  - with a dummy key, the iframe URL is correct and HTML-escaped;
  - no request goes to Google before the click (checked in the browser network panel, recorded in the review).

## W6 SEO and legal

### 16. `seo-foundation`

- **SEO-1 meta:** title/description patterns per page type in the `seo` catalogue, ≤ 60 / ≤ 155 characters, with truncation by sentence boundary.
- **Canonical rules:**
  - listing page 1 → clean path;
  - `?page=N` → self-canonical, title "… – Seite N";
  - any other query-string filter → `noindex,follow` + canonical to the clean path.
- **Social:** Open Graph and Twitter tags with the first photo at 1200 px.
- **SEO-2:** JSON-LD builders in `Seo`, rendered into the `jsonld` block.
- **SEO-3:** `/sitemap.xml` (index plus pages: listing, canton pages from #17 once they exist, venues with `lastmod` = `updated_at`, images via `image:image`) and `/robots.txt` (https sitemap, disallow `/_search/`, `/merkliste`).
- **SEO-5:** error pages (`templates/bundles/TwigBundle/Exception/error404.html.twig`, `error.html.twig`) render with the right status. A test forces a 500 and asserts it is not 404.
- **SEC-1:** security headers through an event listener or `nelmio/security-bundle` (research picks), with a nonce-based CSP. Check AssetMapper's importmap output against the CSP; we load modules with plain `<script type="module" src>` tags.
- **Done when:**
  - application tests cover meta, canonical, robots meta per page type, the JSON-LD (validated against schema.org shapes: required keys present, no email key), the sitemap XML (schema-valid, every venue present) and the security headers on every public response;
  - Google's Rich Results Test passes on the deployed preview or with copied HTML (recorded in the review).

### 17. `seo-landing-pages`

- **Canton pages** `/hochzeitslocations/{canton-slug}`: the listing with the canton filter pre-applied as a **path facet**, its own h1 "Hochzeitslocations im Kanton Zürich", title "Hochzeitslocations im Kanton Zürich: N Locations | Hochzeitslocation", a one-sentence intro from a catalogue template, a self canonical, and a breadcrumb. In the filter UI, choosing a canton navigates to the canton path, keeping other filters as query parameters, which are then noindexed.
- **Decide with evidence:**
  - whether to add town pages (`/hochzeitslocations/zuerich/staefa`) for towns with ≥ N venues, and building-type pages (`/hochzeitslocations/schloss`), with a minimum-result threshold to avoid thin pages;
  - how the original's landing pages rank: search a few queries, note the title patterns, and record them.
  
  Default: canton pages + building-type pages for types with ≥ 5 venues; town pages only for towns with ≥ 5 venues.
- **Linking:** "Andere suchten auch nach" (LP-5) and the footer list link the landing pages; the sitemap includes them.
- **Done when:**
  - application tests cover every landing type, the thresholds (an under-threshold page is 404), canonical/robots rules and the sitemap entries.

### 18. `legal-pages`

- `/impressum` and `/datenschutz`, based on songrequest's legal partials pattern (`templates/site/legal/de/*.html.twig`), with content per LEG-1/LEG-2.
- **Impressum** (Q5): Pick n Roll GmbH, Sonneggstrasse 61, 8006 Zürich, UID-Nr.: CHE-134.034.546 MWST, E-Mail hallo@hochzeitslocation.ch. Keep the company facts in one place (`site.*` container parameters, as in songrequest), so the footer, the JSON-LD `Organization` (#16) and the mails read the same values.
- **Datenschutz:** drafted under nDSG for the owner to review. It names Pick n Roll GmbH as controller and lists the processors and data:
  - Hostpoint and Neon (hosting, EU region);
  - the mail provider and Sentry;
  - Google Maps, loaded only after a click (#15);
  - inquiry data, forwarded to the venue (Q6), with the retention from LEG-2;
  - the Merkliste cookie (functional);
  - no analytics.
- The footer links both pages.
- **Done when:**
  - application tests show both pages render and are linked from every page;
  - every remaining `TODO owner` marker is listed in the Outcome so #20 can block on them.

## W7 Launch

### 19. `performance-caching`

- **Reverse proxy** (S10): Symfony `HttpCache` vs Cloudflare vs both. Decide for Hostpoint + Neon (Q2): `HttpCache` needs only a writable `var/`, and it is what hides Neon's cold start.
  - Implement `Cache-Control`/`Vary`, the `HttpCachePurger` from #9, and a no-session guard (a test fails if any public route starts a session).
  - No ESI needed: the only cookie-dependent bits (Merkliste hearts and count) are client-side by design.
- **Measure against PERF-1** with `make lighthouse` on listing (no filters, three filters), detail (rich venue) and a canton page; with k6 or `ab` for server time (songrequest has a k6 setup to copy); and with byte budgets (CSS, JS, HTML, fonts). Fix what misses: critical CSS inline if needed (nonce), font subsetting, image `sizes`, query tuning.
- **CI:** `make perf-check` runs the byte budgets (no Lighthouse in CI unless it proves stable).
- **Done when:** every PERF-1 number is met and recorded in `docs/performance-baseline.md` with how it was measured.

### 20. `prod-deploy`

- **Target** (Q1, Q2): **Hostpoint** shared webhosting for PHP, **Neon** for Postgres, served at **hochzeitslocation.ch** (the domain is already registered; #20 points it at Hostpoint and enables Let's Encrypt).
- **Research checks on Hostpoint** first, and records the answers here:
  - PHP 8.5 available (else the newest version, and whether the code needs a constraint change);
  - `pdo_pgsql` loadable;
  - gd/Imagick with AVIF and WebP (#5's fallback otherwise);
  - cron interval and PHP binary path;
  - SSH + git access;
  - outbound TCP 5432 to Neon;
  - libpq version and SNI. Songrequest's cyon needed the Neon endpoint ID in the password because its libpq had no SNI.
- **Neon:** the region closest to Hostpoint (measure the round-trip). Plan for the cold start: the free plan suspends compute after inactivity, which costs a cold start, and the CU-hour quota (songrequest's #7 notes) limits how often cron may wake it. Keep the `HttpCache` from #19 in front so visitors rarely wait for it.
- **Starting points:** `../hochzeitslocation/deploy/hostpoint.md`, `deploy.sh`, `crontab.txt`, `backup-db.sh` (Hostpoint-specific knowledge from the Laravel attempt), and songrequest's #7/#8 (Neon, deploy workflow, Sentry, backups).
- **Deploy:** mirror songrequest's #7 deploy:
  - manual GitHub Actions deploy gated on green CI;
  - a `bin/deploy` on the server (`composer install --no-dev`, migrate, `asset-map:compile`, `sass:build`, `cache:clear`);
  - `.env.local` secrets only on the server;
  - HTTPS + HSTS, `/health` check after deploy.
- **Content and media:** the #21 content (~758 venues, ~9,200 photos, about 43 GB of originals plus derivatives in the Laravel layout) reaches the server as rsync of `CONTENT_ROOT` plus a server-side `app:venues:import`, or as locally generated variants rsynced to `public/media`. Research picks one against Hostpoint's disk quota and CPU limits. Hostpoint's quota may rule out keeping originals on the server.
- **Mailbox:** create `hallo@hochzeitslocation.ch` on Hostpoint (the Impressum contact, Q5) and send it a test mail from outside before go-live.
- **Mail:** a sender on `hochzeitslocation.ch` with SPF/DKIM/DMARC set for Hostpoint's SMTP (or the chosen provider), because inquiries go straight to venues (Q6) and must not land in spam.
- **Cron:** `app:inquiries:purge`; the cache warm-up after import.
- **Monitoring and backups** (OP-3): Sentry (EU region, `send_default_pii: false`, prod only); DB backups (Neon PITR or a nightly `pg_dump`, with a restore test); content and media backups.
- **Done when:**
  - the site answers on HTTPS at hochzeitslocation.ch, and the non-canonical host 301s to the canonical one;
  - production runs on the #21 content;
  - a test inquiry to a venue the owner controls arrives and is not flagged as spam;
  - a deploy runs end to end through the workflow;
  - a Sentry test error arrives;
  - a backup restore is tested;
  - Lighthouse against production meets PERF-1;
  - #18's placeholders are replaced.

## Real content (W5, runs alongside the feature cycles; required by #20)

### 21. `legacy-data-import`

- **Q4 answered:** copy the content from the Laravel project. It becomes the production content (S8).
- **Output:** a console command `app:legacy:export-content` (in `Venues\Console`; it may be deleted once production runs on it). It reads the legacy sources and **writes #9's content folders** into `CONTENT_ROOT`. It never writes to our DB directly, so #9's import stays the only write path. Then `app:venues:import` loads them.
- **Sources** (research reads all three and picks the best source per field):
  1. **The Laravel project's MariaDB** (`../hochzeitslocation`, `docker compose up -d db`, port 3307): 758 `source=eventlokale` venues, rooms, terms, towns, Spatie `media` rows with their `custom_properties.source_url`. Its vocabulary was remapped to that project's own wedding taxonomy (`database/data/taxonomy.php`), which is **not** the eventlokale vocabulary we copy 1:1.
  2. **The raw crawl cache** in `~/media/hochzeitslocation-import/eventlokale/`: `venues/` with 5,128 JSON payloads from `/api/guest/location/{id}/get`, plus `all-taxonomies.json` and `index.json`. These carry the **original eventlokale term ids**, which map 1:1 to our vocabulary from #4. They also carry rooms with floor material, the FAQ fields, distances, and possibly the rating score, count, highlights and comments, which the Laravel import may have dropped. Prefer this source for vocabulary, rooms, FAQ, distances and ratings.
  3. **The photos** on the external SSD: an APFS sparse bundle, `MEDIA_ROOT=/Volumes/hl-media`, mounted with `hdiutil attach /Volumes/PortableSSD/hl-media.sparsebundle` (see `../hochzeitslocation/docs/eventlokale-handoff.md`). It holds 9,298 originals in the Spatie layout. Copy originals only; #5 regenerates our variants.
- **Selection:** the 758 venues the Laravel import kept (wedding-suitable, in Switzerland, with a known canton). Record the mapping and drop reasons in a report file.
- **Mapping rules:**
  - eventlokale `label_text`/`type_id`/`boost` → our `tier` (S6, Q7). Research documents the observed label values and their mapping.
  - Contact data: venue email and phone, website and contact person are copied, because inquiries go straight to the venue (Q6). The JSON-LD still never publishes a personal email (SEO-2).
  - Phones are normalised to E.164.
  - `parking`, `single_rooms`, `double_rooms` and other free-text FAQ fields are copied as text, not parsed.
  - Towns are resolved through #3 (ZIP + name), reusing the alias and ZIP-fallback lessons from the Laravel `ResolveTown` (Genf (Geneva), Davos (Platz & Dorf), Altdorf UR, Muri AG, Sihlbrugg).
  - Summaries are capped for the card (LP-2) at a sentence boundary; the full text goes to the description.
- **Known gaps to handle,** from the Laravel handoff doc:
  - 319 photos were discarded there for exceeding a 10 MB cap. Downscale them instead of dropping them if they are in the crawl's `source_url` list and still reachable; otherwise skip them.
  - Venue *Utopia Aarau* has no photos at all; it imports without a gallery and is reported.
  - 3 venues have impossible room heights (290–476 m); these are dropped to NULL.
  - 1 venue has 0/0 coordinates; it falls back to the town centroid.
- **Not in the repo:** the content folders, photos and report stay outside git (`CONTENT_ROOT`, `MEDIA_ROOT`). Tests use a small fictional fixture in the legacy shapes, never the real payloads.
- **Done when:**
  - a dry run reports mapped, skipped and unmapped records, with zero unmapped vocabulary codes;
  - the generated content folders pass #9's validation;
  - `app:venues:import` loads all of them;
  - running export and import a second time changes nothing;
  - the owner spot-checks five venues (one Schloss, one Hotel, one with rooms and documents, one without a rating, Utopia Aarau) side by side with the eventlokale page;
  - the Outcome records the counts (venues, photos, rooms, venues without an inquiry email).

## Out of scope / proposals (not scheduled)

- **CMS / back office** for the owner to manage listings, tiers and photos (explicitly out of scope; #9's files replace it for now).
- **Map view ("Karte")** of the result list.
- **Bulk inquiry from the Merkliste** (eventlokale has it).
- **Home page** with hero search.
- **French, Italian and English** (one cycle per language: catalogues, localized route paths, venue translations, hreflang). S4 makes these additive.
- **A TypeScript/JS framework layer** (only if a real need appears).
- **Analytics** (the owner decided on no consent banner, so any analytics must be cookieless).
- Ratings collected on the site (we display imported ratings only), provider listings, magazine, Award, packages.
