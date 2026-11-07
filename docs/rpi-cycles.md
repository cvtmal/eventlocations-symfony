# deine-eventlocation.ch: RPI cycles

_Last updated: 2026-11-07 · The WHAT lives here (scope, settled decisions, requirements, the cycle order, and Wave G at the end) and in `docs/product.md`, the product view kept by the product owner. The HOW is `CLAUDE.md` and `.claude/rules/`. `KICKOFF.md` is the superseded fork brief, kept for history. The two business requirement documents are `docs/prd/CMO_PRD.md` (go to market) and `docs/prd/CTO_PRD.md` (product blueprint)._

We are building a Swiss listing of **event venues**: company events, seminars, birthdays, apéros, Christmas parties, general meetings and weddings. It has one listing page with filters, one detail page per venue, a Merkliste that needs no login and an inquiry form on every venue. It is modelled on eventlokale.ch's features and behaviour, never on its content. Behind it sits a small **back office** at `/admin` (S12) for at most two staff: a **CMS** for venues, photos, rooms, documents, landing-page texts and text blocks, a **CRM** for the venue operators we sell tiers to and the inquiries their venues receive, and statistics (S17). Venue managers get their own area at `/konto` (S16).

The code was forked once from the sister site hochzeitslocation.ch (E1, E2). The sister site's 57 cycles are done, F0 renamed and stripped the fork, and F3 to F9 turn the rest to events. This file turns the remaining scope into cycles. One cycle = one slug = one pass through Research → Plan → Implement → Review = one squash-merged pull request.

## How to run a cycle

1. **Research** (`/rpi-research <slug>`), given the cycle's section below, **Settled decisions** and **Requirements**. Research and plan may be one pass. Research ends with 2 to 4 options and a recommendation; take it.
2. **Plan** (`/rpi-plan`), then **Implement** (`/rpi-implement`). When run by hand, `/clear` between phases.
3. **Review** (`/rpi-review`) comes from a fresh sub-agent that did not implement. It returns severity-tagged findings; fix every high and medium one. After two failed rework rounds the cycle goes to the tech lead.
4. **Gates**, in this order and in the foreground: `make cs-fix` → `make lint` → `make test`. A UI cycle also runs the checks in S11, including `make perf-check`. New behaviour comes with tests in the narrowest suite that proves it.
5. **Ship:** one pull request titled with the slug, CI green, squash-merged with the slug as the commit subject (see **Running cycles in parallel**). Rework after a merge is a new PR `<slug>-finish`.
6. **Outcome:** in the PR's last commit, after the final rebase, add an **Outcome** paragraph under the cycle's section, flip its overview row to `Done` (a Wave G cycle has no overview row: its Outcome in Wave G §3 means Done), and append current-state lines to the module notes in `.claude/rules/module-<name>.md`. Record what later cycles must know: routes, tables, classes, deviations, follow-ups. Write it for a reader who has not seen the code.

Artifacts land in `.rpi-tracking/{research,plans,changes,reviews}/<date>/<slug>-*.md` (git-ignored). `/rpi <slug>` tells you where you are.

**Owner inputs.** The PO and the tech lead decide; the owner is not asked (2026-11-07). The few facts only the owner holds have placeholders (Wave G §7), so no cycle waits.

## Important

- Never ask the owner to decide. Apply your own recommendation and record it in the research and the PR description; the owner follows it. Ask the tech lead only for a merge conflict that needs a joint decision, a change to a hotspot or architecture rule, an infrastructure blocker, or after two failed rework rounds.
- **eventlokale content (D11, D12).** Copy features, structure and behaviour, never texts, photos, logos, icons, font files, venue data or branding. Nothing from eventlokale goes into the repository, the demo data or the tests, and nothing of it is published. The crawler (`app:crm:crawl-eventlokale`) writes private working data under `CONTENT_ROOT` (git-ignored); the owner writes the public texts. Public photos come from the Google Places API, loaded live: never stored or cached, with the uploader's name on each photo, on the detail page only and never on cards, until the venue uploads its own.
- **Behaviour visits** to the live eventlokale pages go slowly: at most about 20 requests per session (Cloudflare rate-limits at about 35 rapid requests), never with forms submitted. The owner-run crawl paces itself (1.5 s plus jitter, stops after three 429s) and is exempt from that cap.
- **Tone.** Public copy, mails and `/konto` are "du" (E4), with Swiss "ss", never "ß", and `/konto` mails greet "Hallo {name}". `PublicCopyAddressTest` enforces it: it reads every public catalogue (all `translations/*+intl-icu.de.yaml` except `admin`, `cms` and `crm`) and all Twig, PHP and `assets/js` as text, and a capitalised "Sie" at a sentence start fails too, so write "Die Location …". Symfony's formal German messages that a reader can reach are overridden in `validators+intl-icu.de.yaml` and pinned by `tests/Integration/Shared/Translation/FrameworkMessagesTest.php`. Never weaken the test. The staff catalogues (`admin`, `cms`, `crm`) are informal already.
- **Look.** Until F6 has merged, `docs/reference/public/` holds the sister site's boards: use them for layout and structure only, never for colours, type, logo or wording. From F6 on, every UI cycle compares its result with the new boards at 390 and 1440 px, in Claude in Chrome against the local app. Every back-office cycle compares its screens with `docs/reference/admin/` at 1440 px and checks that they stay usable at 390 px.
- The app stays reachable locally at `http://localhost:${NGINX_HOST_PORT}` after every cycle, with demo data loaded (`make db-fresh && make seed`).
- **Keep it simple** (owner): at most two staff users, never on the same thing; no locking or privacy machinery beyond what the law needs; when only unrealistic input fails, lower the limit and refuse it instead of engineering for it.
- Deploys are the owner's alone. Never deploy, never touch production, never touch the triggers of `.github/workflows/deploy.yaml`.

## Running cycles in parallel

Up to seven cycles run at once, one per lane. Wave G §4 groups the cycles into streams A to G; the tech lead assigns streams to lanes.

1. **A standing worktree per lane.** Lane N (L1 to L7) is the worktree `../deine-eventlocation-lN`, created once with `git worktree add ../deine-eventlocation-lN -b <slug> origin/main`. The next cycle in a lane starts with `git fetch && git switch -c <slug> --no-track origin/main`, then `make vendor db-migrate` (and `make admin-install admin-build` if `admin-ui/` changed). Never run two cycles in one working tree.
2. **One Compose project per worktree** (own containers, volume and `app_test`), with the lane's host ports and a pinned network subnet in a git-ignored `compose.override.yaml`. The dev machine's Docker address pool is exhausted, so a project without a pinned subnet fails to start; never run `docker network prune`.

   | Lane | Worktree | `NGINX_HOST_PORT` | `DB_HOST_PORT` | `MAILPIT_HOST_PORT` | Subnet |
   |---|---|---|---|---|---|
   | main checkout | `deine-eventlocation` | 8090 | 5442 | 8035 | 10.247.90.0/24 |
   | L1 to L7 | `../deine-eventlocation-lN` | 810N | 545N | 811N | 10.247.9N.0/24 |

   Example for L1:

   ```yaml
   services:
     nginx:
       ports: !override
         - "8101:80"
     database:
       ports: !override
         - "5451:5432"
     mailpit:
       ports: !override
         - "8111:8025"
   networks:
     default:
       ipam:
         config:
           - subnet: 10.247.91.0/24
   ```

   `make perf-up` is one shared Compose project, `deine-eventlocation-perf`, on 8096 and 5446: only one lane runs it at a time.
3. **Finishing.** `git fetch && git rebase origin/main`, then `make lint && make test` again (tests are skipped for a docs-only diff), push the branch, open the PR, wait for every CI job (`gh pr checks <n> --watch`), then `gh pr merge <n> --squash --subject "<slug>"`. Prove the merge with `git fetch && git log origin/main -3`. Never `--amend`, never force-push shared history, never push to `main` directly, never push tags (they stay local). A conflict that is not trivial goes to the tech lead with a proposed resolution before the merge (D8).
4. **Migrations.** One hand-written SQL migration per cycle, named `VersionYYYYMMDDHHMMSS` from the wall clock at the moment it is written, with a `down()`. The baseline `Version20261106174839` and every merged migration stay untouched. The **Schema ownership** column of the overview (F-cycles) and of Wave G §3 (G-cycles) says which cycle may alter which table. Cycles that can run at the same time never alter the same table; a dependency chain (F9, then G7, then F13 on `inquiries`) adds its columns in turn.
5. **Hotspot files.** Some files have one owner; other cycles only add lines to them and never restructure them. A needed structural change goes to the tech lead first.

   | File | Owner | Others may |
   |---|---|---|
   | `tests/Architecture/*` (module table in `ArchitectureRules.php`, `ModuleBoundariesTest`, the phpat rules) | tech lead | nothing; a new module or dependency is a design change |
   | `templates/base.html.twig`, `templates/layout/*`, `templates/components/*` with `assets/styles/components/*`, `assets/styles/abstracts/_tokens.scss` | F6 `brand-design` | fill the blocks; a missing token goes to the lane's report, the first lane that needs it adds it (append only), and a new colour pair gets a row in `ContrastTokensTest`; a component's parameters or look change only through F6 |
   | `assets/styles/app.scss` (list of partial imports) | F6 | append one `@use` line for their own partial |
   | `src/Search/ReadModel/SearchCriteria.php` and its URL codec | F5 `filter-event` | nothing; filter UIs only render it, F8 and F13 only read it |
   | `templates/search/_card.html.twig`, `search/_filter_bar.html.twig`, `templates/search/filter/*`, `templates/components/_overlay.html.twig` | F5 (filters), F6 (look) | fill their own `{% include %}` slot |
   | `translations/messages+intl-icu.de.yaml` (layout strings) | tech lead | nothing; each module has its own domain file |
   | `translations/*` tone of address, `tests/Unit/Shared/PublicCopyAddressTest.php` | tech lead | nothing; write new copy in "du" and never weaken the test |
   | `config/packages/security.yaml` | tech lead | nothing; guard routes with `#[IsGranted]`; a new firewall is a design change |
   | `config/services.yaml`, `config/packages/translation.yaml`, `src/Shared/Translation/*` | tech lead | append lines to `services.yaml`; nothing else |
   | `templates/admin/base.html.twig`, `templates/admin/layout/*`, `assets/styles/admin.scss` | tech lead | fill the blocks; append one `@use` line for their own partial |
   | `templates/admin/components/*`, `admin-ui/src/components/*`, `templates/admin/form/theme.html.twig` | tech lead | use them; a missing variant goes into their own module folder first |
   | `admin-ui/src/islands.js` (island registry), `admin-ui/src/timeline/types.js` | tech lead | append one line per island or activity type |
   | `admin-ui/package.json`, `admin-ui/package-lock.json` | tech lead | add a dependency only with a one-line reason in the Outcome; on a rebase conflict take `main`'s lock file and run `make admin-install` again |
   | `translations/admin+intl-icu.de.yaml` (shell strings) | tech lead | nothing; CMS strings go to `cms`, CRM strings to `crm` |
   | `deploy/crontab.txt`, `bin/cron-daily` | tech lead | one line per nightly job: console command, crontab line, a Sentry monitor note in `module-shared.md`, idempotent on a second run |
   | `CLAUDE.md`, `.claude/rules/module-<name>.md`, `docs/rpi-cycles.md` | tech lead; the PO owns the decisions and Wave G | append to their module's file; in this file only their own row, section and Outcome; all in the last commit after the final rebase |

6. **Resources.** The Docker VM has 12 GB for all lanes. Never run PHPStan and PHPUnit at the same time in a lane, and never two PHPUnit processes in one lane (`tests/bootstrap.php` clears `var/cache/test` at start). A container that dies with exit 137 ran out of memory: start it again and rerun.

## Overview and order of execution

| # | Slug | Size | Depends on | Schema ownership | Owner input | Status |
|---|---|---|---|---|---|---|
| F0 | `fork-baseline` | M | — | one baseline migration, `Version20261106174839` | — | Done (`4a98cc7`) |
| F0b | `fork-docs` | S | F0 | — | — | Done |
| F1 | `owner-decisions` | – | — | — | — | Done (D1 to D12) |
| – | `rules-condense` | S | `fork-docs` | — | — | Done |
| F3 | `event-vocabulary` | M | F0, F1 | `taxonomy_terms`, `venue_terms`; the wedding-fact columns of `venues` and `venue_translations` | — | Done |
| F4 | `room-layouts` | S–M | F3 | `rooms`, `room_translations` (layout capacities) | — | Todo |
| F5 | `filter-event` | M | F3 | indexes only | — | Todo |
| F6 | `brand-design` | L | F1 | — | — (D23, D32) | Done (`98f38d7`) |
| F6b | `brand-assets` | M | F6 | — | — (D23) | Todo |
| F7a | `du-flip` | S | F0 | — | — | Done (`3c19cad`) |
| F7b | `event-copy-de` | M | F3, F5, F7a | — | Operator facts as `TODO(owner)` (D27); processors in D26 | Todo |
| F8 | `landing-event` | M | F5 | `content_blocks` rows (landing texts) | — | Todo |
| F9 | `inquiry-event` | S–M | F3 | `inquiries` (event columns) | — | Todo |
| F11 | `portal-event` | S–M | F4 | `portal_submissions` (event step) | — | Todo |
| F12 | `business-model` | M–L | F1 | `venues.tier` and `tier_rank`, `crm_deals.tier`, entitlement data | Tier names and prices | Todo |
| F13 | `multi-inquiry` | M–L | F9, G7 | `inquiries` (parent and child deliveries) | — | Todo |
| F14 | `content-onboarding` | M | F3, F4, G5 | — | Spreadsheet (fixture until it arrives); consent D29, follow-ups D32 | Todo |
| F15 | `prod-setup` | M | F0 | — | — (host D26) | Todo |
| F16 | `launch-verification` | S–M | all except F13, G14 to G16 | — | — | Todo |

Sizes: S about half a day, M one to two days, L three days or more. F2 and F10 (languages) are gone with E3 and D13; the numbers are not reused. F7 is split: F7a `du-flip` (done) closed the last gaps of the du flip (E4) and widened the guard test, F7b `event-copy-de` is the rest of the copy. The cycles G1 to G16 are in Wave G §3 at the end of this file; where Wave G changes a dependency, the table above already shows it (F13 after G7, F14 after G5).

**Order.** F0, F0b, F1, F6 and F7a are done. F3 runs, then the rest by dependency. Lanes and the critical path are in Wave G §4. F15 is minimal (docs for a generic host), and F16 comes last.

**History.** The sister site's cycles #0 to #57 are done and no longer in this file. Their bodies and Outcomes: `git show 7e96eee:docs/rpi-cycles.md` (the tag `fork-point` is local). Mentions such as `#43` in Wave G, in the module notes and in code comments point there.

## Owner inputs

The owner delegated every decision to the PO and the tech lead on 2026-11-07 (D23 to D32 settle the former owner inputs). The owner holds only real-world facts; each has a marked placeholder, so no cycle waits. The list is in Wave G §7.

## Settled decisions

These are settled so that parallel cycles do not each decide them differently. A cycle that finds strong evidence against one records it in its research and continues on the settled path. A settled decision changes only by an edit to this file.

### Fork decisions E1 to E7 (owner, 2026-11-06)

| # | Decision |
|---|---|
| E1 | **Fully separate** from hochzeitslocation.ch: own database, own CMS and CRM, own venues and managers. Nothing is shared at runtime. |
| E2 | **Fork once, then diverge.** Fixes from hochzeitslocation are ported by hand when needed, with `git cherry-pick` from the remote `hzl` (fetch only; pushing to it is blocked). There are no shared packages and no multi-site codebase. |
| E3 | **German only.** The i18n machinery stays (localized routes, `*_translations` tables), but no French, Italian or English is planned. The research found that adding a language later needs an `i18n-core` cycle first, because the wedding site's promise that a language only adds catalogues and route paths does not hold. |
| E4 | **"du" everywhere** in the public copy, mails and `/konto`. The wedding site uses "Sie", and `tests/Unit/Shared/PublicCopyAddressTest.php` enforces it. Turn that test around so it enforces "du", and rewrite every public catalogue. The staff catalogues (`admin`, `cms`, `crm`) are informal already. Swiss German still means "ss", never "ß". |
| E5 | **Event filters** replace the wedding ones: event types (Firmenanlass, Seminar, Geburtstag, Apéro …), catering, technology. Seating layouts are already on rooms (Bankett, Seminar, Apéro, Konzert). |
| E6 | **Another business model** than the wedding site: other tiers and pricing for venues, maybe expiring paid entries. Details are open, see §3. |
| E7 | **Domain:** `deine-eventlocation.ch`. Also register `deineeventlocation.ch` as a redirect. |

Section references in these rows (§3, §4) point into `KICKOFF.md`. E4 was started by the baseline (F0) and is finished by F7a `du-flip`; E6's open details are settled by D2, D14 and D15; the consequence of E3 is S4.

### Owner decisions D1 to D12 (owner, 2026-11-06, via the tech lead)

| # | Decision | Feeds |
|---|---|---|
| D1 | **Venues come from three sources.** (a) Managers register themselves in `/konto`, after a mailing and calls by the owner. (b) The owner's own contacts and spreadsheet, imported through `app:venues:import`. (c) hochzeitslocation customers who also host events, **only with their consent**, imported once as new records with new texts. This is a one-time import, not runtime sharing, so E1 holds. | F14 |
| D2 | **Freemium.** A free basic entry plus 2–3 paid tiers, invoiced by hand; no online payment. When a paid entry expires it falls back to free and never goes offline. Paid tiers unlock more photos, PDFs, a better ranking and visible contact data. Every venue, free ones included, has the inquiry form. | F12 |
| D3 | **One venue per inquiry** at launch. A request to several venues from the Merkliste (F13) comes after launch. | F9, F13 |
| D4 | **No room-layout search filter.** Visitors search by guest number only; the layouts show on the detail page. F4 stays small, and F5 has no layout criterion. | F4, F5 |
| D5 | ~~The owner supplies the brand.~~ **Superseded by D23** (2026-11-07): F6's brand is the brand. | F6 |
| D6 | **The operator is a different company** from hochzeitslocation's. | F7 |
| D7 | **Weddings are listed:** "Hochzeit" is one event type among the others. The tech lead had advised against it; the owner overruled. A venue's texts must differ between the two sites. | F3, F8, F14 |
| D8 | **Git:** teammates may push branches, open PRs and squash-merge to `main` without asking, and resolve conflicts together with the tech lead. No direct pushes to `main`. Deploys stay the owner's. | all |
| D9 | **Name and contact:** the site is called "deine-eventlocation.ch", and its contact address is `hallo@deine-eventlocation.ch`. | F0, F7 |
| D10 | **Hosting is not Hostpoint;** the host is not chosen yet. It must offer PHP 8.5, Postgres 18 (or Neon reached from outside), cron and SSH. F0 leaves the deploy scripts as they are, and F15 adapts them once the owner picks a host. **Host chosen in D26.** | F0, F15 |
| D11 | **The eventlokale crawler stays** (overrides the "Drop" row in §4). The owner wants eventlokale's venues as a content source; D12 settles how. | F0, F14 |
| D12 | **Source (d), eventlokale venues.** The crawler fetches all of eventlokale, every category, with facts, texts and photos, as internal working data before launch. The owner was told about the risk under Swiss UWG Art. 5 lit. c and accepted it ("crawl everything, it's only for myself, we're prelaunch"). eventlokale's texts and photos are **never published**; the owner writes new texts. Public photos come from the Google Places API, loaded live: never stored or cached, with the uploader's name on each photo, on the detail page only and never on cards. They show until the venue uploads its own photos in `/konto`. | F14 |

In these rows "F0" is the cycle `fork-baseline`, and the "Drop" row in §4 is the one in `KICKOFF.md`. D13 to D22 (2026-11-07) are in Wave G §1 at the end of this file, and some of them refine the rows above. D17 refines D3: the default flow stays one venue per inquiry (IQ-2), and F13, a request to up to 5 venues from the Merkliste, is a second path, built last in the run, that does not block launch (Wave G §4). D14 and D15 settle D2's "2–3 paid tiers" as Free + Pro.

### S1. Stack

- **Runtime:** Symfony 7.4 LTS on PHP 8.5, PostgreSQL 18, Doctrine ORM 3 / DBAL 4, Twig, Symfony Mailer, AssetMapper, nginx + php-fpm in Docker Compose (`php`, `nginx`, `database`, `mailpit`).
- **Same stack as the sister site hochzeitslocation.ch** (forked once, E2), so a fix there can be ported by hand with `git cherry-pick` from the remote `hzl` (fetch only).
- **No Node at runtime and no JS framework or library on the public site:** no Stimulus, Turbo, Swiper or jQuery. Build-time tools in a container are fine, e.g. the dart-sass standalone binary through `symfonycasts/sass-bundle`, or Lighthouse CLI for checks.
- **The one exception is the back office** (`/admin`, S12): Vue 3 islands, compiled at build time by Vite in a Node tools container. Node never runs on the server, and no public page loads Vue.
- **Two public exceptions** (owner, 2026-11-05):
  - **the near-me page** (`/hochzeitslocation-in-meiner-naehe`, renamed by F5 to `/eventlokal-in-meiner-naehe`): it loads the Google Maps JavaScript API from Google on page view. Google's script is not ours, not vendored and not served by AssetMapper; our own `near-me.js` loads it, and its CSP and `geolocation=(self)` apply to that route only. No other public page loads a map library.
  - **the Google rating on a venue page:** when the server has `GOOGLE_MAPS_JS_KEY` and the venue a Google place ID that staff did not hide, our own `google-rating.js` loads the same Google script after the page's load event and once the rating box is in view, and builds Google's Places UI Kit element (`gmp-place-details-compact`). The page then carries the `google_places` CSP, set per response, not per route; `geolocation` stays off. Keyless, without a place ID or hidden, the page loads nothing from Google.
- **PostgreSQL over MariaDB.** No requirement here needs MariaDB, and Postgres gives us:
  - the owner's existing Postgres toolchain and rules;
  - `pg_trgm` + `unaccent` for "Name eingeben" and the Ort/PLZ autocomplete, with typo and umlaut tolerance ("Zuerich", "Zurich" → Zürich);
  - `cube` + `earthdistance` for "within N km of a town" without PostGIS;
  - GIN indexes and partial indexes for facet filtering;
  - CHECK constraints, generated columns and `NULLS NOT DISTINCT` unique keys for the validation strategy.

  Shared-host availability is the one argument for MariaDB. D10 answers it: the host must offer PHP 8.5, cron and SSH and reach Postgres 18 (Neon is the plan).

### S2. Architecture

The base is a module-first modular monolith, `src/<Module>/<Layer>/` with namespace `App\<Module>\<Layer>`, with the class-modifier rules, `final readonly` value objects and read models of the owner's house style. Port/In/Out layering, deptrac, query and event buses and RabbitMQ are overkill here.

| Module | Holds | May depend on |
|---|---|---|
| `Shared` | `EntityId`, `DomainException`, translation helpers, Swiss formatting Twig extension, HTTP cache helpers, legal pages, health | — |
| `Geo` | cantons, towns, postcode/town lookup, distances | — |
| `Media` | image variant generation, `<picture>` Twig helper | — |
| `Venues` | venue aggregate (translations, terms, rooms, photos, documents), detail page, import | Geo, Media |
| `Search` | `SearchCriteria`, listing read model, counts, ordering, similar venues, autocomplete endpoints, listing page | Venues, Geo, Media |
| `Favorites` | Merkliste cookie codec, toggle endpoint, `/merkliste` page | Search, Venues |
| `Inquiries` | inquiry form, entity, mails | Venues |
| `Seo` | sitemap, robots, JSON-LD builders, landing page definitions | Search, Venues, Geo |
| `Stats` | cookieless statistics: beacon, day-file spool, ingest, daily rollup, statistics read models (S17) | Venues, Inquiries |
| `Admin` | back-office shell: staff users, login + 2FA, layout, dashboard, extension points, audit log, Vue island loader (S12) | — |
| `Cms` | back-office screens and read models for venues, rooms, photos, documents, landing-page texts, content blocks | Admin, Venues, Geo, Media, Stats |
| `Crm` | companies, contacts, timeline, tasks, deals, inquiry inbox, e-mail (S13) | Admin, Venues, Geo, Inquiries, Portal |
| `Portal` | location managers: accounts, access to venues, claims, onboarding, submissions and their staff screens (S16) | Admin, Cms, Venues, Geo, Media, Stats |

- No public module depends on `Admin`, `Cms`, `Crm` or `Portal`. `Crm` uses `Portal` only for its published events, as it does with `Inquiries`, so `Portal` never names a CRM class. `Cms` and `Crm` do not depend on each other: a CRM panel on a CMS page goes through an `Admin` extension point (S12).
- **Layers:** `Controller`, `Command`, `CommandHandler`, `Entity`, `Repository`, `ReadModel` (DBAL queries returning `final readonly` view objects), `ValueObject`, `Exception`, `Form`, `Console`, `Twig`, `Mailer`, `EventListener`, `Event`, `Extension` (a module's implementations of the `Admin` extension points), `Security`, `Messenger`.
- **Reads:** public pages go controller → `ReadModel` with hand-written SQL, and never hydrate entities for rendering. **Writes:** controller or console command → command → `command.bus` → handler. There is no query bus.
- **Enforcement:** phpat inside PHPStan **level 8**, PHP-CS-Fixer `@Symfony` + `@Symfony:risky`. Test suites are `Unit`, `Integration`, `Application` and `Architecture`, with Foundry fixtures. The validation strategy is three layers (form → handler → DB constraint). Migrations are hand-written SQL only, each with a `down()`.

### S3. Frontend

- **Templates:** Twig includes and embeds under `templates/components/`.
- **CSS:** Sass partials compiled to one `app.css` by `symfonycasts/sass-bundle` (dart-sass binary, no Node). There is one partial per component, so parallel cycles each add their own file. Design tokens are CSS custom properties on `:root`.
- **JS:** vanilla ES modules, one per behaviour (`carousel.js`, `overlay.js`, `live-count.js`, `region-filter.js`, `merkliste.js`, `share.js`). Each loads only on the pages that need it. There is no importmap of third-party packages.
- **Progressive enhancement:** filter overlays are HTML `popover` elements opened by `popovertarget`, so they work without JS. Filters are plain GET forms. Carousels are CSS scroll-snap containers that JS only adds arrows and counters to. Without JS, the only thing lost is the live "N Resultate anzeigen" count.
- **Icons:** an inline SVG sprite built from Lucide (ISC licence). Our own icons are drawn in the same line style; we do not copy eventlokale's SVGs.
- **Font:** see S15.
- **Colour and type tokens:** CSS custom properties on `:root` (`assets/styles/abstracts/_tokens.scss`), with values from the brand (S15). Every text and background pair meets WCAG 2.2 AA, and the contrast table (`ContrastTokensTest`) has a row for each.

### S4. Language (German only, E3)

- `default_locale: de`, `enabled_locales: [de]`. No other language is planned (E3, D13) and none is advertised.
- The machinery stays: ICU YAML catalogues with **one translation domain per module** (`venues+intl-icu.de.yaml`, `search+intl-icu.de.yaml`, …) and layout strings in `messages`. Keys follow `<context>.<description>`. No user-facing string appears in Twig or PHP outside a catalogue. Public tone is "du" (E4).
- **Fixed vocabularies** are PHP enums or code-keyed rows, and their labels come from catalogues.
- **Venue-authored text** lives in `venue_translations (venue_id, locale, slug, summary, description, meta_description)`, plus `room_translations` and `document_translations`. The venue name is a proper noun and stays on `venues`.
- **Routes:** every public route is declared in Symfony's localized form (`path: ['de' => '/…']`), and a test fails on a public route without it. **Query parameters** use stable, language-neutral codes (`?canton=zh&guests=150`).
- Swiss formatting (`1'500`, `30.10.2026`) comes through a Twig extension, never by hand.
- **A later language is not additive.** The sister site promised that a language only adds catalogues and route paths. The fork research (`docs/research/fork-research.md` §3.2) found that this does not hold (slugs, sitemap, hreflang, place names, mail language), so a language needs an `i18n-core` cycle first. FR, IT and EN are in Out of scope.

### S5. URLs

The paths below are the sister site's, as forked. **F5 `filter-event` renames every `/hochzeitslocation…` path to `/eventlokal…`** (and records the new ones in its Outcome); F8 adds the event landing pages. Route names (`home`, `listing`, `venue_show`, …) do not change.

- `/` is the home page (HP-1), route `home`.
- `/hochzeitslocations` is the listing; `?page=2` paginates. There are **no seeds in URLs and no redirects** on listing requests.
- `/hochzeitslocations/{canton-slug}` (e.g. `/hochzeitslocations/zuerich`) are canton landing pages and `/hochzeitslocations/{canton}/{town}` town landing pages; F8 adds event type and event type × canton pages.
- `/hochzeitslocation/{venue-slug}` is the detail page. Slugs are ASCII-transliterated German (`ü` → `ue`). An old slug redirects with a 301 through `venue_slug_history`. Beside it: `/hochzeitslocation/{venue-slug}/telefon` (reveal), `/hochzeitslocation/{venue-slug}.md` (Markdown page) and `POST /hochzeitslocation/{venue-slug}/anfrage` (inquiries).
- `/hochzeitslocation-in-meiner-naehe` is the near-me page.
- `/merkliste` and `POST /merkliste/{number}` serve the Merkliste.
- `/_search/count` and `/_search/places` are fragment and JSON endpoints, `noindex` and disallowed in `robots.txt`.
- `/sitemap.xml`, `/robots.txt`, `/llms.txt`, `/impressum`, `/datenschutz` and `/health` complete the set.
- The site has never been live, so renaming a path needs no redirect.

### S6. Ranking (paid attributes)

- `venues.tier` ranks paid above free. Tier names, their number and what each unlocks are F12's (D2); settled: Free + Pro at CHF 199 a year (D14, D15, D25). The sister site's five tiers (`beliebte_location` … `basic`, with a generated `venues.tier_rank`) are in the schema as forked and F12 replaces them.
- Within a tier, venues are ordered by `md5(venue_id || seed)`, where the seed is today's date in Europe/Zurich. Every visitor sees the same order for a day, paging is stable, and pages are cacheable.
- Staff set the tier in the CMS or with "Tier auf Location anwenden" on a won deal (S13). A new location starts on the free tier (S16).

### S7. Merkliste without login

- **Cookie:** `merkliste` holds comma-separated venue **numbers**. `venues.number` is an `INT GENERATED ALWAYS AS IDENTITY UNIQUE` public id, which keeps the cookie small. It is capped at 100 entries, `Max-Age` 400 days, `Secure`, `SameSite=Lax`, and *not* `HttpOnly`, so JS can read it.
- **Caching:** public pages never read the cookie, so they stay cacheable. After load, JS marks the hearts and the header count.
- **Without JS:** each Merken button is a small POST form to `/merkliste/{number}`. It toggles the cookie server-side and answers 303 back to the `Referer`, or to `/merkliste`.
- **`/merkliste` page:** it reads the cookie server-side and sends `Cache-Control: private, no-store` and `noindex`.
- **Privacy:** this is a functional cookie, described in the Datenschutz page. There is no consent banner, by the owner's decision.

### S8. Content files, then the CMS

- **The database is the master.** Venues are created and edited in the CMS and by managers in `/konto`. Both dispatch the same `Venues` commands (**one write path**), so slug history, room summaries, the photo pipeline (S9) and the cache purge live once, in Venues handlers.
- **Bulk path:** real content can be written as one YAML per venue plus a photo folder, outside `public/` (default `content/`, configurable via `CONTENT_ROOT`, git-ignored; format in `docs/content-format.md`) and loaded idempotently with `app:venues:import`. The import never silently overwrites a CMS edit: a venue with `cms_edited_at` later than its last import is skipped and reported, unless `--overwrite-cms-edits` is given. Database and `MEDIA_ROOT` backups are the content backups.
- **Where venues come from (D1, D12):** managers who register themselves in `/konto`; the owner's own spreadsheet through the import; hochzeitslocation customers who consent, once, as new records with new texts; and the eventlokale crawl as internal working data. F14 builds what is missing for these.
- **Dev and test** use Foundry: a `DemoStory` with about 60 fictional venues across all 26 cantons, with generated placeholder photos, plus `CrmStory` and `PortalStory`. `make seed` loads them; F3 turns their wording to events.
- No eventlokale content goes into the repository or the demo data, and none is published (**Important**, D12).

### S9. Images

- Originals are kept outside `public/` (`MEDIA_ROOT`).
- Derivatives are generated **at import time, never on request.** They are written as `public/media/<venue-number>/<photo-id>-<width>.<avif|webp|jpg>` at widths 360/640/960/1280/1920. Each photo row stores width, height and dominant colour, which becomes the placeholder background.
- Pages use `<picture>` with AVIF, then WebP, then JPEG, an explicit `width`/`height`, `loading="lazy"` everywhere except the LCP image, and `fetchpriority="high"` on the LCP image.

### S10. Caching

- Public GET pages start no session and set no cookies; an application test asserts this.
- They send `Cache-Control: public, s-maxage=600, stale-while-revalidate=86400`.
- The shared cache is Symfony's `http_cache` (it works on shared hosting); `Shared\Http\HttpCacheClearer` purges it on the venue import and on every CMS or `/konto` write that changes a public page.
- `/admin` and `/konto` are the only paths with a session or cookies. They send `Cache-Control: private, no-store` and `X-Robots-Tag: noindex, nofollow`, and the reverse proxy never stores them.

### S11. Budgets and quality gates

- **PERF-1 budgets** (mobile Lighthouse, simulated slow 4G):
  - LCP ≤ 2.0 s, CLS ≤ 0.05, TBT ≤ 100 ms;
  - Lighthouse ≥ 95 in Performance, Accessibility, Best Practices and SEO (on the near-me page and on a venue page with the Google rating, a miss caused only by Google's own script is accepted and recorded in the cycle's Outcome; our part, measured without a key, must meet the gate);
  - listing HTML ≤ 60 KB gzip;
  - CSS ≤ 25 KB gzip in total;
  - JS ≤ 15 KB gzip per page (our own scripts; the Google Maps script on the near-me page and on a venue page with the Google rating is S1's stated exception and not counted; `google-rating.js` itself is counted);
  - fonts ≤ 2 files on first view (the fonts Google Maps loads on the near-me page, and Google's element on a venue page with the Google rating are outside this count);
  - uncached server time p95 ≤ 200 ms for the listing and ≤ 120 ms for the detail page, with the demo data ×10.
- **Every cycle:** `make cs-fix` → `make lint` → `make test`.
- **Every UI cycle also:**
  - the visual check from **Important** at 390 and 1440 px, in Claude in Chrome against the local app;
  - `make lighthouse URL=…` on the pages it touched;
  - a keyboard-only walkthrough;
  - the W3C validator on the rendered HTML.
- **Every back-office UI cycle instead:**
  - the comparison with `docs/reference/admin/` at 1440 px, and a check that every screen it touched is usable at 390 px;
  - a keyboard-only walkthrough, and `make lighthouse URL=…` with an Accessibility score ≥ 95 on one screen it touched (logged in: `make lighthouse URL=… ADMIN=1`, or `PORTAL=1` for `/konto`);
  - `make admin-lint` and `make admin-test`;
  - the ADM-8 JS budget.

### S12. Back office (`/admin`)

- **Users:** staff only. There is no sign-up. `app:admin:create-user <email> --role=admin|editor` creates an account, and `app:admin:reset-2fa <email>` resets 2FA. `admin` may do everything, including users; `editor` may use the CMS and the CRM but not users or deletions of companies and contacts. A role check is `#[IsGranted]` on the controller, never in Twig alone.
- **Security:** a firewall `^/admin` only, so public pages stay without a session (S10). It has form login, `login_throttling` (5 attempts per 15 min), TOTP 2FA required for every user (`scheb/2fa-bundle` + `scheb/2fa-totp`; research may pick an alternative), a session cookie with `SameSite=Strict`, `Secure` and `HttpOnly`, a 30-day idle timeout, CSRF on every form and on every JSON write (a header token), and the S10 headers. The TOTP secret is encrypted at rest with a key from the environment.
- **Routes:** `/admin/…`, unlocalized (`LocalizedRoutes::UNLOCALIZED`), German UI only. There is one catalogue domain per module: `admin` (shell), `cms`, `crm`. Content forms show one tab per enabled locale (only `de` now) (S4).
- **Rendering:** server pages first, with Vue islands only where interaction pays off. A page is Twig plus Symfony Forms with POST → redirect → GET, like the rest of the app. A **Vue 3 island** is mounted on `<div data-island="name" data-props='{…}'>` by one loader entry, which lazy-loads the island's chunk. There is no SPA, no client router and no global store. Islands talk to JSON endpoints under `/admin/api/…`, which are ordinary controllers that read through read models and write through commands. The islands are the photo manager, the room editor, the global search / command palette, the inline property editor `record-properties`, the timeline with its composer (the e-mail composer is a tab in it), the pipeline board (`deal-board`) and a combobox; the registry is `admin-ui/src/islands.js`. Everything else is a server page, and a new island needs a reason in its research.
- **Toolchain:** `admin-ui/` at the repository root is a Vite project: plain JavaScript with `<script setup>` single-file components, and no TypeScript (GEN-2's spirit). Vitest + `@vue/test-utils`, ESLint. A `node` tools service in Compose (`profiles: [tools]`, like `lighthouse`) runs `make admin-install`, `make admin-build`, `make admin-watch`, `make admin-test` and `make admin-lint`. The build writes `public/admin-build/` (git-ignored) with Vite's `manifest.json`, and a Twig function `admin_island_assets()` reads the manifest. `make test` builds it first, as it does Sass, and `make lint` includes `admin-lint`. CI runs both. Production gets the built files from the deploy workflow, never from Node on the server.
- **Styles:** one Sass root `assets/styles/admin.scss` (compiled by the existing sass-bundle) with its own tokens (`--a-*`) from the design reference, the shared font files and the Lucide sprite. Twig pages and Vue components use the same classes; SFCs have no `<style>` blocks, so there is one CSS system. CSP-safe: no inline scripts or `style` attributes in Twig, and Vue's runtime-only build (templates are precompiled, so there is no `unsafe-eval`).
- **Reads and writes:** as S2. Admin list and edit screens read through `Cms\ReadModel` / `Crm\ReadModel` (DBAL view objects). An edit form is filled from a read model into a form data class, and submitting it dispatches a command. Venue commands live in `Venues` (S8, one write path). Each edit form carries the `updated_at` it was loaded with, and the handler rejects a stale save with a `StaleEdit` domain exception ("Diese Location wurde inzwischen geändert"). This is optimistic locking without a version column. **Inline field writes** (`record-properties`) have no form, so the field's loaded value is the version: a write sends it as `expected`, and the handler compares it under the row lock with the field's current value, not with `updated_at`, which has whole seconds only and would make a page's second field stale after its first save. A mismatch is a 409, and an unchanged value is a success with no audit row.
- **Audit log:** a `command.bus` middleware (in `Admin`) writes `admin_audit_log` (actor, command, subject type and id, changed fields, time) for every command that implements `Shared\Messenger\AuditableCommand`. Public modules implement that Shared interface and never see `Admin`. The CRM timeline shows the audit entries of a company's venues.
- **Extension points** (the "open for extensions" part; interfaces in `Admin\Extension`, implemented in a module's `Extension` layer, autoconfigured as tagged services, ordered by a priority and filtered by role). A new back-office feature plugs in here, without editing the shell:
  - `NavigationItem`: an entry in the sidebar (section, label, icon, route, badge count);
  - `DashboardWidget`: a card on `/admin` (title, Twig template, size);
  - `RecordTab`: a tab on a record page (`venue`, `company`, `contact`, `deal`), e.g. Fotos/Räume/Dokumente on the venue;
  - `RecordPanel`: a side panel on a record page, e.g. the "CRM" panel on the CMS venue page;
  - `SearchProvider`: results for the global search (venues, companies, contacts, deals);
  - `TimelineActivityType`: a kind of timeline entry with its renderer.
- **Design reference:** `docs/reference/admin/` holds the design from the planning session: the canvas source (`*.dc.html`, `canvas.json`), a `README.md` with the screen list, the `--a-*` token table and the link to the live, clickable design (https://claude.ai/artifact/LDRuCKBiQoQkUXNAG7vEtY). It holds a 1440 px screenshot per screen. Its look: calm, light work surfaces (a dark theme is a proposal) with the brand colour as the one accent; a HubSpot-like structure of a left sidebar, list views with filters in the query string, and record pages with properties on the left, the timeline or content in the middle and associations on the right. The admin shell and UI kit turn it into tokens and components.
- **Uploads:** one file per request, so a slow host never times out on a batch. The photo manager uploads sequentially with progress. Photos are processed synchronously with S9's generator at upload time (never on request). The limits are 25 MB per photo and 15 MB per PDF, and F15 sets PHP's `upload_max_filesize`/`post_max_size` to match.
- **Not in the back office:** a WYSIWYG page builder, custom fields, a separate admin domain (all proposals). Location managers get their own area outside it (S16).

### S13. CRM model (HubSpot, minimal)

- **Objects:** **companies** (the venue operators we sell to; on screens a *Location*; a company has 0..1 venue, its *Eintrag*, `crm_companies.venue_id`, unique), **contacts** (people on a company's card, never records of their own: `crm_contacts.company_id` is NOT NULL, the e-mail is lower-case and unique per company, no `kind`, no owner), **deals** and **tasks**. An organizer who sends an inquiry is not a contact: the inquiry stays in `inquiries`, with a link row (`crm_inquiry_links`) and, when the venue has a company, an `inquiry` entry on its timeline (F9 adds the event fields). There are no leads, tickets, products or quotes.
- **Lifecycle:** a company has a `stage` of `prospect`, `customer` or `former_customer`. It is derived only for a company with a won deal: `customer` while one such contract runs (no end, or an end today or later), `former_customer` once all have ended. Every other company keeps its hand-set stage, so an open upsell demotes nobody, and when its last won deal leaves, the company falls back to `prospect`. Contacts have no lifecycle.
- **Timeline:** one `crm_activities` table (type, `occurred_at`, actor, a short title, a body, and a JSONB `payload` per type), linked to objects through one join table per object (`crm_activity_companies` and `crm_activity_deals`). Types are code-keyed rows in `crm_activity_types` (`note`, `call`, `meeting`, `email_logged`, `inquiry`, `email_sent`, `deal_stage_changed`). `position` is unique. A later cycle adds a type by inserting a row in its own migration, never by altering a CHECK. System events (venue published, tier changed) come from `admin_audit_log` at read time and are not copied.
- **Tasks:** title, due date, assignee (an admin user), done flag, linked to one object. "Heute fällig" and "Überfällig" sit on the dashboard.
- **Owner:** companies and deals have an optional owner (an admin user). With one or two staff it is mostly informative.
- **Lists:** every object list has search, filters and sorting in the query string, so a saved view is a bookmark. CSV export goes to `admin` users only.
- **Vocabularies** (company stage, deal stages, activity types, lost reasons) are code-keyed rows or enums with labels in the `crm` catalogue (S4). Pipeline stages are rows, so the owner's later wish for another stage is a migration, not a code change.
- **Money:** CHF only, stored as integer Rappen (`amount_rappen BIGINT`), shown with `swiss_number`. No VAT logic and no invoicing.
- **Personal data (nDSG):** a contact goes with its company (`ON DELETE CASCADE`). There is no per-contact export, erasure or purge (all went with #55); the contacts CSV is for `admin` users only. Inquiry data and its CRM link row follow the inquiry retention (LEG-2) under `app:inquiries:purge`. The Datenschutz page names the CRM as an internal processing purpose (section `legal.privacy.crm`: venue contacts and inquiry data).

### S14. (dropped)

The sister site's owner data (the Wix export and its facts). It does not apply here. The number stays unused so that references to S15 to S17 still resolve.

### S15. Brand design

- **The owner supplies the brand** (D5): logo, colours and typeface. F6 `brand-design` implements it and does not design one. Until the owner delivers, F6 ships a placeholder: a text wordmark, a calm palette that passes WCAG 2.2 AA and a self-hosted typeface under an open licence.
- **One swap point:** all colours and the font family are CSS custom properties in `assets/styles/abstracts/_tokens.scss`; the logo is one file; the typeface is one file with its metrics. Swapping the brand later edits those files and the contrast table, never every component.
- **Rules that carry over:** the typeface is self-hosted, because the CSP is `font-src 'self'; style-src 'self'` and a Google Fonts request would add a processor to the Datenschutz text; at most two font files on first view (S11); boards are not code (no inline styles, no `/_blob/` images: build from Sass partials and the sprite); no logo on photos; the favicon follows the logo.
- **The sister site's brand must go:** its pink, cream, Figtree and `logo-hzl.png` are not ours. F6 removes them from `assets/` and `public/`.
- A listing card for a venue without its own photos shows a neutral brand placeholder per event type (D32).
- The back office is not redesigned: F6 changes its accent tokens and font only, and `docs/reference/admin/` stays its layout reference.

### S16. Location-Konten (`/konto`)

Owner, 2026-11-01. Location managers register, take over or create their location and keep it up to date. The aim is both less data entry for the owner and a way for locations that want to do it themselves. The back office stays staff-only, and managers get a separate area.

**Golden rules.** They decide every open question about `/konto`.
1. Super simple, as simple as possible.
2. Reuse wherever possible. The account looks like a copy of the back office.
3. No 2FA: e-mail and password.
4. A super short onboarding: log in, then a simple multi-step form.

- **Separate from staff:** own module `Portal`, own table `portal_users`, own firewall on `^/konto(/|$)`, own session cookie. A manager has no path into `/admin`, and public pages stay free of a session (S10).
- **One write path:** the account dispatches the existing `Venues` commands, so the import guard, the cache purge, the stale check and the audit log come along (S8, S12). `Portal` reuses `Cms\Form` and may depend on it. The staff side (review queue, account list, panels) lives in `Portal` and plugs into the back office through S12's extension points.
- **States:** `venues.status` keeps its two values. A location being written or under review is `unpublished`, and its review state lives in `portal_submissions`. Public queries do not change.
- **Registration:** name and e-mail, no password. The mail's link opens the password page, and saving there sets the password and confirms the address in one step. So only the mailbox's owner can ever set a password, and an address cannot be taken before its owner arrives. A resend is the forgotten-password form or registering again.
- **Review:** "Veröffentlichen" never publishes by itself. Only the first publication and every return from offline go through the owner. After that a manager's edits go live at once, and staff see them afterwards, so a queue stays small for two people.
- **Claims:** a location may already exist (imported or prepared by us), and a manager who cannot find theirs would create a duplicate. A manager presses "Zugriff anfragen" on an existing location, the request lands in the back office, and we decide. Nothing is automatic. The order of work is access, then edit, then new locations.
- **What stays with staff:** name, slug, tier, rooms, documents, the Google place ID and deletion; F11 moves rooms and documents into `/konto`. Tiers are what the site sells, and a rename moves a URL.
- **New locations start on the free tier.** A tier is sold through a deal (S13), as before.
- **Money:** nothing in the app; paid tiers are invoiced by hand (D2). The sister site's rule that a newly created location writes the CRM task "Rechnung stellen" 30 days after its first publish is in the code as forked, and F12 replaces it with the tier model (a free entry is not invoiced).
- **Rights:** the last wizard step has one checkbox: the manager may represent the location and holds the rights to its texts and photos. The time is stored, and that is all the legal machinery.
- **Not built:** magic links, a claim link to the location's mailbox, a claim link on the public detail page, a wizard table, a token table, a Vue wizard, an external password check, new packages.
- **Wave G changes parts of this:** G1 replaces the single rights checkbox by per-photo grants, G5 adds signed preview links and G6 verifies a claim by a code mailed to an address on the venue's own domain; generic and competing claims still go to staff.

### S17. Statistik

Owner, 2026-11-03. Statistics like Google Analytics, built on our side: no GA, no third party, no cookie, no consent banner. Staff see them in `/admin`, each manager sees their own locations in `/konto`, and prospective clients get the media-kit figures. All ten recommendations of the research (`venue-stats`) are accepted.

- **New module `Stats`:** Stats → Venues, Inquiries. `Cms` and `Portal` may depend on Stats; no other module may. `ArchitectureRules::MODULES` and `ModuleBoundariesTest` list it.
- **Collection never wakes Neon:** the beacon `POST /_stats` appends to a day file in `var/stats/spool` and opens no database connection. This is a deliberate exception to S2's write path: no command, no bus, because the bus's transaction connects to Postgres.
- **One job, two triggers:** the 03:15 cron step and a "Jetzt aktualisieren" button on `/admin/statistik` run the same ingest (`app:stats:ingest`). It loads the files into raw `stats_events`, kept forever, and rebuilds `stats_daily`. So figures read "Stand: <last ingest>", not "bis gestern".
- **`templates/base.html.twig`** carries the `stats.js` `<script>` after `merkliste.js`, with the blocks `stats_page` and `stats_venue` that pages fill.
- **JS budget per page:** S11's 15 KB gzip is measured per page, as it is worded (base's scripts plus the page template's), not over all of `assets/js`. `AssetBudgetTest` measures home, listing, detail and Merkliste, and fails on a public script no page references.
- **Lighthouse stays at 100** with the beacon on; re-check it after any change to `stats.js`.
- **Privacy:** `/datenschutz` gains a "Statistik" section. The IP is never stored; the visitor hash uses a salt per day that is deleted after the day. Global Privacy Control turns the beacon off.
- **Not built:** bot filtering (raw rows keep the data for a later one), a consent banner, a third-party tool, real-time figures.

## Requirements

IDs are referenced by the overview and the cycles.

**General**
- **GEN-1** German UI only (E3), with every string in translation catalogues (S4).
- **GEN-2** Server-rendered Symfony + Twig. No TypeScript or JS framework or library; every public page is usable without JS (S3). The back office is the one exception: Vue islands, no TypeScript (S12).
- **GEN-3** The look follows the brand (S15). eventlokale remains only the record of behaviour; its look is never copied.

**Brand (BRD)**
- **BRD-1** Every public page matches its board in `docs/reference/public/` at 390 and 1440 px (the boards of F6; until then the sister site's boards there are a record of layout only).
- **BRD-2** The typeface is self-hosted, no request to a third party for type, at most two font files on first view (S11).
- **BRD-3** The logo in header and footer with `width` and `height` set, and no logo on photos or on pink. A matching favicon.
- **BRD-4** Platform copy uses "du" and Swiss spelling ("ss", never "ß") throughout, in catalogues, mails, validation messages and meta texts (E4; enforced by `PublicCopyAddressTest`).

**Home page (HP)**
- **HP-1** `/` is a real, cached, indexable page, in the order of the board: hero with search, categories, four steps, Spotlight, call to action, text, regions.
- **HP-2** The hero search is a plain GET form to `listing` with Kanton, Anlass and Anzahl Gäste (F5 replaces Location-Art by the event type). It works without JS and adds no script.
- **HP-3** Category tiles (the event types, F3 and F7b) link to the listing with their code, through `listing_path()`, so a live landing page is used when one exists.
- **HP-4** Spotlight shows three published venues in the order of LP-4, so a paid tier and the daily seed decide, with the Merken button.
- **HP-5** Region links are the live canton landing pages, never a fixed list.
- **HP-6** The long text section is a `ContentBlockDefinition`, so staff can edit it, with catalogue text as the fallback.

**Listing page (LP)**
- **LP-1** The listing (`/hochzeitslocations` as forked, renamed by F5 to `/eventlokale`) has a hero band with the h1 (F7b sets the wording; canton pages "… im Kanton Zürich"), "N Resultate", the filter bar, the result list, and pagination "1 - 48 von N Resultaten" with page links: 48 per page, 24 if PERF-1 fails. The filter bar carries the event type (F5).
- **LP-2** Card content as in `docs/reference/listing-card.png`, which is the record of behaviour only; the look is the board in `docs/reference/public/` (GEN-3):
  - photo on the left;
  - top row: capacity "50 - 450" (person icon), town "Ittigen" (pin icon), and "Merken ♡" on the right;
  - tier badge, then the venue name;
  - summary clamped to three lines (~150 characters, stored ≤ 200). The sister site's rating column is retired.

  The card stacks on mobile, and the whole card links to the detail page.
- **LP-3** The card photo is a carousel (eventlokale has a single photo here; this is our addition). It holds up to 8 photos, browsed by swipe, arrow buttons or the keyboard without leaving the list. A counter shows "2 / 8". Only the first photo loads eagerly, and only for the first two cards.
- **LP-4** Results are ordered by paid tier, then by the daily seed (S6).
- **LP-5** "Andere suchten auch nach" link block below the results, linking canton and town landing pages (F8 adds event type and event type × canton pages).
- **LP-6** Active filters show as chips in the filter bar with "×". The filter bar has Region, Anlass (F5), Anzahl Gäste, "weitere Filter +" and a round Merkliste button. Every overlay has "Filter löschen".
- **LP-7** _Replaced by F3/F5._ (Wedding only: the card's Preisniveau and Ambiente tags. F3 removes the wedding facts; F5 decides what the card shows of the event vocabulary.)

**Filters (FI)**. The screenshots named below are the record of behaviour only; the look is the boards in `docs/reference/public/` (S15, GEN-3).
- **FI-1 Region** (`filter-region.jpg`, `filter-region-selected.png`):
  - full-screen overlay with large decorative circles;
  - h2 "Wo soll dein Event stattfinden?" (the original's wording; F7b confirms it);
  - tabs **Kanton** | **Ort/PLZ** and a large underlined input ("Kanton eingeben") with autocomplete;
  - on the right, an interactive SVG map of the 26 cantons with lakes. Hover darkens a canton and shows a white tooltip with its name; click selects it, and it stays dark.
  - "N Resultate anzeigen" button (live count), "Filter löschen" link, round "×" close.

  It behaves like the original and looks as the boards of F6 draw it.
- **FI-2 Ort/PLZ:** autocomplete over towns and postcodes. Selecting one filters venues in that town or within a radius around it (as built).
- **FI-3 Guests:** an overlay with the h2 "Wie viele Gäste erwartest du?". As forked it has four radio bubbles on the venue's maximum capacity (`GuestBand`, "bis 50 Gäste" to "über 150 Gäste"). F5 replaces the bands by ones that fit events, or by a number input; D4: the guest number is the only capacity criterion, and there is no layout filter. Same footer buttons as FI-1.
- **FI-4 Weitere Filter** (`filter-more.jpg`): overlay "Wähle weitere Filter und Möglichkeiten" with a sticky footer ("N Resultate anzeigen", "Filter löschen"). Its sections, in eventlokale's order:
  1. **Suche eine konkrete Location:** "Name eingeben".
  2. **Lage:** round icon buttons Am Fluss, Am See, Auf dem Land, In den Bergen, In der Stadt.
  3. **Erreichbarkeit:** Nähe Bahnhof, Nähe Autobahn, Nähe Flughafen.
  4. **Gebäudeart:** Bar, Club · Eventlocation · Hotel, Seminarhotel · Partyraum · Restaurant · Schloss · Kongresszentrum · Waldhütte · Bauernhof, Scheune · Berghütte, Alphütte, plus "Alle Gebäudearten", which expands to the full list.
  5. **Infrastruktur:** WLAN / Internet, Klimaanlage, Bühne, Dusche, Garderobe, Heizung & Lüftung, Küche zur Nutzung, Backstage Räume, Rednerpult.
  6. **Mobiliar:** Flipchart, Magnetwand, Moderatorenkoffer, Stühle, Tische.
  7. **Technik:** Tontechnik, Beamer / Leinwand, Dolmetscheranlage, Lichtanlage, TV / Screens.
  8. **Umgebung:** Garten, Spa & Wellness, Terrasse, Spielplatz, Grill, Golf.
  9. **Raumangebot:** dropdowns Anzahl Räume, Grösster Raum (m²), Min. Raumhöhe (m).

  Sections 1 to 9 follow eventlokale's order. F3 re-curates the vocabularies for events and F5 adds the event type section (E5). The full Gebäudeart list is in eventlokale's public `/api/guest/location/all-taxonomies` (vocabulary `buildings`).
- **FI-5** Filters combine: OR inside the setting, building and event type groups, AND everywhere else (`ListingSql`).
- **FI-6** The whole filter state is in the query string (S4), so it is shareable, bookmarkable and works with the back button.
- **FI-7** _Replaced by F3/F5._ (Wedding only: the Hochzeit section of the Weitere-Filter overlay. F3 removes the wedding groups, F5 adds the event type filter in its place.)

**Merkliste (MK)**
- **MK-1** "Merken ♡" on cards and "Location merken" on the detail page toggle the entry. The header heart shows the count.
- **MK-2** No login: a cookie (S7) that persists for 400 days.
- **MK-3** `/merkliste` lists saved venues as cards in saved order, each with a remove button, plus an empty state.
- **MK-4** A toast "{Name} wurde deiner Merkliste hinzugefügt." with a link to the Merkliste.

**Detail page (DP)**, as in `detail-page-top.jpg` and eventlokale's live detail page for behaviour; the screenshot is the record of behaviour only, and the look is the `docs/reference/public/` board (S15, GEN-3):
- **DP-1** URL per S5; title pattern "{Name} in {Ort} | {site.name}" (F7b settles the wording).
- **DP-2** Hero carousel, inset in the page frame as the `Detail` board draws it (BRD-1), with a "Bilder ansehen" button that opens a full-screen gallery (`<dialog>`, keyboard and swipe).
- **DP-3** Sticky anchor nav: Beschreibung, Bilder, Kontakt, Standort.
- **DP-4** Right-hand card "Anfragen & Merken", sticky on desktop:
  - primary "Verfügbarkeit prüfen", which jumps to the inquiry form;
  - outlined "Location merken" (MK);
  - "Teilen": Web Share API, with a fallback menu of copy link, WhatsApp and E-Mail;
  - "Drucken": `window.print()` with a print stylesheet; without JS the button is hidden.

  On mobile it becomes a bottom bar.
- **DP-5** Name with tier badge; "PLZ Ort"; an icon row with main building type, setting and "50 - 450 Personen"; the description.
- **DP-6 Bewertung:** the Google rating via the Places UI Kit (stars, count and Google's attribution, below the head), when the server has the key and staff did not hide it. The sister site's own rating, labels and highlights are retired.
- **DP-7** Labelled lists: Gebäudeart, Lage, Geeignet für, Infrastruktur, Mobiliar, Technik, Umgebung.
- **DP-8 Räume:** a horizontal card carousel, each card with photo, name, capacity, "Rauminfos" (text dialog) and "Eckdaten" (dialog with area m², height m, floor material and capacities per seating type).
- **DP-9 Häufige Fragen:** Eigenes Catering? · Tageslicht? · Parking?
- **DP-10 Standort:** address, then distances with names ("0.9 km bis Bahnhof Papiermühle", "3.2 km bis Autobahn A6", "102 km bis Flughafen Basel-Mulhouse"), then a Google map.
- **DP-11 Kontakt:** "Telefonnummer anzeigen" (click to reveal), E-Mail, website, contact person; Free entries hide phone and website (D15, F12).
- **DP-12 Dokumente:** PDF links with titles.
- **DP-13** "{Name} kontaktieren" form:
  - three promises: Kostenlos · Unverbindlich · Direkt zur Location;
  - Anlass (the event type, no default), Anzahl Gäste, Wunschtermin (F9 adds company, layout, time of day, catering and budget);
  - an editable message preview starting "Liebes {Name}-Team";
  - Vorname/Name, E-Mail, Telefon;
  - the "Anfragen" button, and a confirmation state.
- **DP-14** _Retired_ with the sister site's own reviews; there is no replacement (no invented ratings or testimonials, D18).
- **DP-15** "Ähnliche Locations": 12 compact cards (photo, badge, name, town, capacity) plus "Alle Locations".
- **DP-16** The print stylesheet prints the facts, rooms, contact and address without navigation or forms.
- **DP-17** _Replaced by F3._ (Wedding only: the Hochzeit section with the owner facts. F3 removes the wedding facts and keeps the ones that suit events; the Instagram link stays in Kontakt, DP-11.)

**Inquiries (IQ)**
- **IQ-1** Inquiries are stored in `inquiries` with venue, full payload, created_at and a status for the mail outcome. The schema is designed so the CRM can take them over unchanged: it reads `inquiries` and never alters the table.
- **IQ-2** In production, a mail goes directly to the venue's inquiry address (one venue per inquiry, D3), with the guest's address as `Reply-To` and a sender on `deine-eventlocation.ch`. The guest gets a confirmation copy. Both mails are German and in the site's look.
- **IQ-3** Spam protection without a CAPTCHA: honeypot field, minimum fill time, rate limiter per IP (Symfony RateLimiter), server-side validation.
- **IQ-4** `INQUIRY_RECIPIENT_OVERRIDE` redirects all venue mails in dev and staging (Mailpit), so no real venue is ever mailed from outside production. Production leaves it unset; a test asserts the production config does not set it.

**SEO, security, legal**
- **SEO-1** One h1 per page. Unique title and meta description patterns, canonical, Open Graph (`og:type` = `website` for the listing, `place` for venues) and Twitter tags.
- **SEO-2** JSON-LD: `EventVenue` + `LocalBusiness` on venues (address, geo, telephone (Pro entries only, D15), maximumAttendeeCapacity, image, **no** personal email), `BreadcrumbList`, `ItemList` on listings, `WebSite` + `Organization` on the root layout.
- **SEO-3** `/sitemap.xml`, paged if over 1,000 URLs, with `lastmod`; `/robots.txt` with https sitemap lines.
- **SEO-4** Indexable landing pages per canton and per town, and (F8) per event type and event type × canton. Each has its own h1, title, intro sentence and links. Query-string filter combinations are `noindex,follow` with a canonical to the clean path.
- **SEO-5** Real status codes: a true 404 for missing venues, 410 for removed ones, 5xx for errors (never the 404 template), 301 for old slugs.
- **SEO-6** hreflang-ready: the helper exists and renders `de-CH` plus `x-default` now.
- **SEC-1** Security headers: CSP with nonces (no `unsafe-inline`), `frame-ancestors 'none'`, `X-Content-Type-Options`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy`, HSTS one year. Viewport without `user-scalable=no`.
- **LEG-1** `/impressum` and `/datenschutz` (inquiry data, Merkliste cookie, Google Maps, hosting, mail provider, Sentry), linked in the footer. The operator (D6) is a `TODO(owner)` placeholder until the owner supplies it (D27); the processors are D26's. F7b fills both.
- **LEG-2** Inquiry data retention: inquiries are deleted after 12 months, and the Datenschutz page documents it.

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
- **PERF-3** Public pages are cacheable (S10). The listing query uses indexes only (checked with `EXPLAIN`), with no N+1 (one query for cards, one for their photos, one for the count).

**Data (DATA)**
- **DATA-1** Content-file import (S8).
- **DATA-2** Demo data via Foundry (S8).
- **DATA-3** _Dropped_ (wedding only: the Wix CSV). Venue content comes from the sources of D1 and D12 (F14).
- **DATA-4** _Replaced by F3._ (Wedding only: the owner facts of the sister site.)
- **DATA-5** _Replaced by F3/F14._ (Wedding only: the Wix owner data import.)

**Operations (OP)**
- **OP-1** Docker dev stack, Makefile, CI (lint + test) on GitHub Actions.
- **OP-2** Production deploy with HTTPS, a manual deploy workflow gated on green CI, and a health check.
- **OP-3** Error monitoring (Sentry, EU region) and database backups.
- **OP-4** The back office in production: the deploy ships the built `admin-ui` files, PHP's upload limits match S12, `MEDIA_ROOT` is persistent and backed up (S8: the database and media are the master), and the first `admin` user with 2FA exists.

**Back office (ADM)**, per S12
- **ADM-1** Staff login at `/admin/login` with e-mail and password, then TOTP. Logout. Login throttling. No sign-up and no password-reset mail (an `admin` user resets passwords from the users screen or the console).
- **ADM-2** Roles `admin` and `editor` (S12). A users screen (`admin` only) lists, creates, deactivates users and resets their 2FA.
- **ADM-3** An audit log of every back-office write (S12), with a screen (`admin` only) filtered by actor, subject and date.
- **ADM-4** The shell per `docs/reference/admin/`: sidebar with sections (Übersicht, CMS, CRM, Einstellungen), a top bar with global search (⌘K / Ctrl+K), user menu, flash toasts, breadcrumbs; a dashboard of widgets. German UI from the catalogues.
- **ADM-5** Extension points (S12) with a test that a fake module's navigation item, dashboard widget, record tab and record panel appear.
- **ADM-6** The back office never changes public behaviour: public responses still start no session and set no cookie, `/admin` is `private, no-store` and `noindex`, and `robots.txt` disallows `/admin`.
- **ADM-7** WCAG 2.2 AA in the back office too: keyboard-operable tables, forms, drag and drop with a keyboard alternative (move up/down buttons), visible focus, labelled controls, errors linked to their fields.
- **ADM-8** Budgets: shared admin JS (loader + Vue runtime) ≤ 60 KB gzip, each island chunk ≤ 30 KB gzip, admin CSS ≤ 30 KB gzip. A server page loads no island it does not use. Uncached admin pages answer in p95 ≤ 300 ms locally with the real data.

**CMS**
- **CMS-1** Venue list (`/admin/locations`): search by name or number, filters for status, tier, canton and "unvollständig" (no photo, no inquiry e-mail, summary missing, no main building), sorting, 50 per page, the counts per status. Every row links to the editor and to the public page.
- **CMS-2** Venue editor in sections that match the venue's fields. **Stammdaten:** name, tier, capacity. **Texte:** summary (with a ≤ 200 counter), description, meta description, parking, one tab per locale. **Adresse & Lage:** street, ZIP/town through the place lookup, coordinates with a "Ortsmitte übernehmen" button, distances with names. **Merkmale:** chips per term group, with main building and main setting. **Hochzeit:** _wedding only; F3 replaces it by the event facts (event types, catering, technology)._ **Kontakt:** phone, inquiry e-mail, website, contact person, Instagram. **Häufige Fragen.** **Bewertung:** the card "Google-Bewertung" with the Google place ID and the hide switch. Every rule of the table CHECKs is a form error before it is a DB error (validation strategy).
- **CMS-3** Create a venue (it starts unpublished), publish and unpublish (publishing needs a summary, a main building and ≥ 1 photo), change the tier, delete only when unpublished and only for `admin` (with confirmation; media removed via `MediaPublisher::removeVenue()`).
- **CMS-4** A name change proposes a new slug; saving keeps the old one in `venue_slug_history` (301, S5). The slug can be edited by hand and is checked for uniqueness.
- **CMS-5** Rooms: add, edit (name and description per locale, capacity, area, height, floor material, the four seating capacities), reorder and delete. The venue's room facts are recomputed on every save (`summariseRooms()`).
- **CMS-6** Photos: upload (JPEG/PNG/WebP/HEIC if gd reads it; ≤ 25 MB) with variants generated at upload (S9), reorder by drag and drop and by keyboard, alt text per locale, assign to a room or the venue, delete, see the first photo marked as the card/LCP image. A replaced original gets a new photo id.
- **CMS-7** Documents: upload a PDF (≤ 15 MB), title per locale, reorder, delete.
- **CMS-8** Google rating: staff keep the Google place ID and the switch "Google-Bewertung ausblenden" in the editor card "Google-Bewertung". The sister site's own reviews and ratings are retired.
- **CMS-9** Preview: any venue, published or not, can be viewed as its public detail page at `/admin/locations/{number}/vorschau` (inside the admin firewall, `noindex`, never cached, with a "Vorschau" banner). Published venues also get an "Auf der Website ansehen" link.
- **CMS-10** Every CMS write that changes a public page purges the affected URLs through `HttpCachePurger` (the detail page, the listing, the venue's canton page, the sitemap).
- **CMS-11** DB is master (S8): the import guard with `cms_edited_at` and `--overwrite-cms-edits`, and a "Zuletzt bearbeitet von … am …" line from the audit log.
- **CMS-12** Pages and text blocks: the intro text, SEO title and meta description of each landing page (canton, town, building type and, from F8, event type), and a registry of named text blocks (Markdown, safe-rendered) that modules declare for their slots, e.g. the listing intro or the footer note. Legal pages stay in Twig under version control and are not editable here.

**CRM**, per S13
- **CRM-1** Companies (shown as Locations): list, create, edit (name, legal name, website, phone, e-mail, address, stage, owner, notes), assign and release the Eintrag (one venue at most), delete (`admin`). `app:crm:bootstrap` and a button create one company per venue that has none, with the venue's contact person as a contact on its card. It is idempotent.
- **CRM-2** Contacts: a row on its company's card (first name, last name, e-mail, phone, position): add, edit, remove. There is no contact list or record page; the contacts CSV is for `admin` (S13).
- **CRM-3** Record page per object as in the design reference: properties on the left (inline editable, saved per field), timeline in the middle, associations on the right (company ↔ contacts ↔ venues ↔ deals), and record tabs and panels from the extension points.
- **CRM-4** Timeline: log a note, call, meeting or e-mail (manually) with date and text; edit or delete your own entries; filter by type. System events from the audit log appear read-only.
- **CRM-5** Tasks: create from any record or the task list, with a due date, an assignee and done/undone; a task list (`/admin/crm/aufgaben`) with "Meine", "Heute", "Überfällig"; dashboard widgets.
- **CRM-6** Lists: search, filters, sorting in the query string, 50 per page, CSV export (`admin`), global search results (S12 `SearchProvider`).
- **CRM-7** Deals: name, company, venue, tier, amount (CHF), contract start and end, expected close date, stage, owner, lost reason. A pipeline board (Kanban) per stage with a sum per column; drag and drop plus a keyboard "move to stage" menu; a list view as an alternative. Stages: Neu → Kontaktiert → Angebot → Verhandlung → Gewonnen / Verloren.
- **CRM-8** Won and renewals: a won deal offers "Tier auf Location anwenden", which dispatches Venues' tier command (audited, cache purged). A deal whose contract ends within 60 days shows in the "Verlängerungen" widget, and `app:crm:renewal-tasks` (daily cron) creates one renewal task per deal, idempotently. "Verlängern" copies the deal into the next period.
- **CRM-9** Inquiries: every inquiry gets a link row (`crm_inquiry_links`) and, when the venue has a company, an `inquiry` entry on that company's timeline; the organizer is not a contact. An inbox `/admin/crm/anfragen` lists them (those of a venue without a company too) with a status (neu, gesehen, erledigt) and the mail outcome. `app:crm:backfill-inquiries` imports the history. Ingestion runs after the inquiry is stored and never makes the guest wait or fail.
- **CRM-10** E-mail: compose from a company or deal record that has an address (to that address, optional CC), from a template with merge fields (`{contact.first_name}`, `{location.name}`, `{venue.name}`; single braces, so a template is never Twig), sent through Symfony Mailer from the `deine-eventlocation.ch` sender with the staff member's name and `Reply-To: hallo@deine-eventlocation.ch`, logged as an `email_sent` activity with its delivery status. Replies arrive in the mailbox and are not tracked (inbox sync is a proposal). `INQUIRY_RECIPIENT_OVERRIDE` covers this too: outside production it redirects every CRM mail, CC dropped, so no real address is mailed.
- **CRM-11** Personal data: per S13 a contact goes with its company, and there is no per-contact export, erasure or purge command. Inquiry data and its CRM link row go with the inquiry under `app:inquiries:purge` (LEG-2, 12 months).

**Location-Konten (POR)**, per S16
- **POR-1** A location manager registers with name and e-mail and chooses a password through a link sent by mail, which also confirms the address. Login is e-mail and password, with no 2FA. A forgotten password is reset by mail.
- **POR-2** The account lives at `/konto`, in German, looks like the back office, and stays usable at 390 px. It never opens a door into `/admin`.
- **POR-3** Staff see all accounts and can deactivate one.
- **POR-4** Every write by a manager lands in the audit log under that manager's name.
- **POR-5** A registered manager finds an existing location and asks for access with one button. The request shows up in the back office, and staff approve or reject it. Nothing is automatic.
- **POR-6** A manager can hold several locations, and a location can have several managers. Staff can add and remove managers.
- **POR-7** A confirmed manager becomes a CRM contact on the location's company, which is created if missing. A CRM failure never reaches the manager.
- **POR-8** A manager edits texts, address, facts, FAQ, contact details, maximum guests and photos of their own locations. Name, slug, tier, rooms and documents stay with staff (F11 moves rooms and documents into `/konto`).
- **POR-9** Edits on a published location go live at once. Staff see them on the dashboard and in the audit log.
- **POR-10** A manager can take a location offline. Only staff delete one.
- **POR-11** A new location is created through a short multi-step form that saves every step and resumes where the manager left off. Possible duplicates are offered as a claim.
- **POR-12** "Veröffentlichen" sends the location to review. The CRM contact is created at this moment; the location stays off the site.
- **POR-13** Staff accept or reject. Accept publishes the location, reject sends a reason and lets the manager try again. Both mail the manager. Going back online after being offline takes the same path.
- **POR-14** _Replaced by F12._ (The sister site's rule that a newly created location is paid and writes the CRM task "Rechnung stellen" after its first publish. Under D2 a free entry is not invoiced.)

## Cycles

One section per cycle: scope, what it depends on, the schema it owns, and when it is done. Every cycle also meets the gates in **How to run a cycle**. The cross-cutting list in Wave G §4a is written for the Wave G cycles; the F-cycles meet it where it applies (cache purge, migrations, Foundry stories, mails).

### F0 `fork-baseline` (M, done)

**Outcome** (merged as `4a98cc7`, 2026-11-06). The fork was renamed (`site.*`, the 2FA issuer, trusted hosts, `CheckProductionEnvCommand`, mail and copy strings) and stripped: the Wix, legacy and owner importers (`Venues\…Wix*`, `Legacy*`, `Owner*`), the customer-sheet import (`Crm\…Customer*`) and the retired rating entities are gone; the eventlokale crawler stays (D11). The 25 migrations are one hand-written baseline, `migrations/Version20261106174839.php`, because the new site has no production data. Split out of F0: the docs (F0b), the module notes (`rules-condense`) and the last gaps of the du flip (F7a); F0 itself moved the public catalogues to "du" and turned `PublicCopyAddressTest` around. The deploy scripts, workflows, `.htaccess` and crontab still describe the Hostpoint host (D10; F15 adapts them).

### F0b `fork-docs` (S, done)

**Outcome.** This file replaces the sister site's 3,161-line plan. The standing S-sections are kept and adapted (S4 and S6 rewritten, S14 dropped, S15 reduced to the brand stub); the requirements keep their IDs, with wedding-only ones marked replaced; cycles #0 to #57 are gone (see **History**); Wave G is appended as the PO's master copy. `CLAUDE.md` and `README.md` are rewritten, `KICKOFF.md` is kept for history, and `docs/product.md` and `docs/prd/` are the PO's files, committed as written. Follow-ups for `rules-condense`: the module notes cite `docs/rpi-cycles.md ### NN` Outcomes and `#NN` cycle numbers that now live in git history, and `.claude/rules/i18n.md` still says a language only adds catalogues (S4 says otherwise); `module-portal.md` (lines 33-34) and `module-search.md` (line 84) still quote the old "Sie" wording.

### F1 `owner-decisions` (done)

**Outcome.** D1 to D12 are recorded above. The inputs they leave open are in **Owner inputs**.

### `rules-condense` (S, done)

**Depends on** `fork-docs`. **Owns** no schema. **Scope.** Bring `.claude/rules/module-*.md` down to current-state notes: what exists, and the rules for changing it. Remove the build histories, the `#NN` cycle numbers and the "Outcome: `docs/rpi-cycles.md` `### NN.`" pointers. Keep the convention rules (`code-quality`, `cqrs`, `database`, `frontend`, `php-architecture`, `read-model`, `validation`), and correct `i18n.md` to S4. **Done when** no note cites an Outcome that is not in this file, every module note describes the code as it is, and `make lint` is green.

**Outcome.** The 13 module notes and `i18n.md` in `.claude/rules/` are current-state notes: 354,262 bytes (374,250 with the lines `site-import`, `funnel-scorecard` and `brand-design` appended meanwhile, folded in at the rebase, the duplicated G8 paragraph in `shared` and `venues` collapsed to one) down to 184,772 (49%). `crm`, `search`, `i18n`, `portal`, `admin` and `venues` sit at 42 to 45%, the other eight at 52 to 67%: a first pass at 43% overall had still cut rules, two independent read-only reviewers compared every old note with its new one and found them (the admin login and session rules, "an `HttpCachePurger` never throws", the `data-overlay-form` and empty similar-venues guards, the inquiry mail context key, the content-block lock, the `bin/deploy` protected paths), and restoring the high and medium findings cost 14 KB. Each note opens with `Append current-state lines for your cycle at the end (last commit).`, keeps its `paths:` frontmatter and its `### <Module>` title (code comments cite `CLAUDE.md ### Admin`), and says what it holds and when to read it by hand. The `#NN` cycle numbers, `docs/rpi-cycles.md ### NN` pointers, FR/IT/EN plans and one-off measurements are gone, and stale claims were corrected against the tree: one baseline migration (plus `site-import`'s), no `app:crm:purge`, no Wix/legacy/owner importers, customer import or `bin/prod-import export`, the public cache TTL is a day (`PublicCache::PAGE_TTL`), not 600 s. `i18n.md` says German only (E3) and that a second language needs an `i18n-core` cycle first; `module-portal.md` and `module-search.md` quote the "du" and "Hallo" wording; `module-cms.md` holds the list of `cms` and `stats` keys that keep "Location" instead of "Eintrag" because `/konto` shares them; `CLAUDE.md` "German copy" lost its "until `rules-condense`" clause; `docs/performance-baseline.md` names the Compose project `deine-eventlocation-perf`. Left for later: S10 says `s-maxage=600` and S12 and CMS-6 say 25 MB per photo (code: a day, 10 MB); `docs/operations.md` and `docs/content-format.md` still describe the removed exporters, the customer-import runbooks and a backup schedule that `backup.yaml` no longer has; `.env` line 51 cites the removed `BATCH_MAX_PIXELS`; `FrenchLocaleProbeTest`, `tests/Fixtures/I18n/FrenchProbeKernel` and the `_locale_tabs` docblock still assert that a language only adds catalogues; `frontend.md` "Back office" overlaps `module-admin.md`.

### F3 `event-vocabulary` (M, done)

**Depends on** F0, F1. **Owns** `taxonomy_terms`, `venue_terms` and the wedding-fact columns of `venues` and `venue_translations` (`price_level`, `capacity_seated`, `capacity_standing`, `party`, `has_curfew`, `curfew_at`, `instagram`, `ambience`, `capacity_note`, `party_note`, `opening_hours`). **Scope.** The event vocabulary replaces the wedding one (E5, D7). Re-curate `suitable_for` as the event types (Firmenanlass, Seminar, Geburtstag, Apéro, Weihnachtsfeier, Generalversammlung, Hochzeit and so on; "Hochzeit" is one type among the others), re-curate the catering and technology groups, and take out `building.wedding_venue` and the wedding groups (`ceremony`, `vendor`, `usage`, party and curfew). Facts that suit events stay (price level, seated and standing capacity, opening hours, Instagram, the useful `amenity`, `dining` and `language` codes); the research records the split. Whatever reads a removed column stops reading it in the same cycle, the listing card's tags (`card__tags`, LP-7) included. Rename `TermGroup` cases, `ChangeVenueWeddingFacts`, `DetailWedding`, `VenueWeddingType` and the `_wedding` templates accordingly. Migration rows, catalogues, `docs/content-format.md` and its schema, `DemoStory`. **Done when** no wedding-only group, enum case, template or catalogue key is left; event types, catering and technology are taxonomy groups with German labels that `app:venues:import` accepts and the CMS edits; the detail page shows them under "Geeignet für"; `make seed` shows venues across event types; one migration with `down()`; tests.

**Outcome** (merged 2026-11-07).

- **Vocabulary.** 118 `taxonomy_terms` rows in ten groups; `TermGroup` = `setting, building, suitable_for, infrastructure, furniture, technology, surroundings, amenity, catering, language` (enum order = CHECK order = schema key order, tests pin both). `suitable_for` = 17 event types (`corporate_event, seminar, conference, workshop, meeting, product_presentation, general_assembly, team_event, christmas_party, club_event, apero, birthday, family_celebration, wedding, party, concert, exhibition`). `technology` = `projector_screen, wifi, sound_system, video_conferencing, stage_lighting, flipchart_moderation, tv_screens, interpreting_system` (wifi, stage and the flipchart terms moved there). `catering` is new: `in_house, external_allowed, bar_drinks, byo_drinks, buffet, finger_food, coffee_break, grill, vegetarian, vegan, gluten_free, allergy_friendly`. `amenity` keeps 10 codes (overnight, parking, accessibility, outdoor ...). Gone: `ceremony, dining, vendor, usage` and `building.wedding_venue` (`seminar_room` is primary chip 3).
- **Migration** `Version20261107140511`, one, with `down()`: drops `venues.party/has_curfew/curfew_at` and `venue_translations.party_note`, deletes the CMS landing texts of `wedding_venue`, and swaps the term rows from one list (rows that stay keep their `venue_terms`; terms that moved group are not remapped, there is no production data). `EventVocabularyMigrationTest` runs it in both directions on data.
- **Classes and routes.** `ChangeVenueEventFacts` + handler (groups `Amenity, Catering, Language`), `VenueTranslation::describeEvent`, `DetailEvent` (`VenueDetail::$event`, `venues/detail/_event.html.twig`, section "Angaben zur Location"), CMS card `VenueCard::Event` (POST `/veranstaltung`, `admin_locations_event`, `VenueEventType/Data`), Portal `LocationCard::Event` / `WizardStep::Event` (`veranstaltung`, `portal_location_event`), content format version 2 (no `party`, no `party_note`, ten groups; docs and schema equal the database). `PartyAllowed`, `DetailWedding`, `ChangeVenueWeddingFacts` and the wedding templates are deleted. `SearchCriteria` lost `ceremony`, `usage`, `curfew` and the amenity code `external_caterers` (removal only; old URLs degrade silently); the overlay partial is `search/filter/_more_event.html.twig`.
- **Not done on purpose.** The inquiry `Occasion` is still the wedding list (F9). `suitable_for` and `catering` have no filter parameter yet (F5). Route paths are untouched (F5). The labels of `setting`, `building`, `infrastructure` and `surroundings` are still the copied ones (F7b or the owner).
- **Follow-ups.** F11 decides where `suitable_for` is edited (the Merkmale card today) and untangles the two "Ausstattung" labels (wizard step and amenity group). F4, F9 and F11 pick one source for "own catering" (FAQ `own_catering` against `catering.in_house` and `external_allowed`). Fact rows have no `overflow-wrap`, so an unbroken 60-character ambience overflows at 390 px.

### F4 `room-layouts` (S–M)

**Depends on** F3. **Owns** `rooms` and `room_translations`. **Scope.** The layout list and the capacities per room (as forked: Seminar, Bankett, Apéro and Konzert as `capacity_<layout>_{min,max}` columns and the `Seating` enum; the research decides whether a `room_capacities` table pays off before more layouts are added), the CMS room editor island, the detail page's room dialog and the content format. D4: layouts show on the detail page only, there is no layout filter, and F5 has no layout criterion, so this cycle stays small. **Done when** staff edit layouts and capacities per room, the room dialog shows them, `RoomSummary` still derives the venue's room facts, the content format and its schema carry them, one migration with `down()`, tests.

### F5 `filter-event` (M)

**Depends on** F3 (F4 is not needed: no layout filter, D4). **Owns** indexes only. **Scope.** The route rename (PO decision 2026-11-07, owner-corrected the same day, priority "Event look"): every `/hochzeitslocation…` path becomes an `/eventlokal…` path, because the owner wants to rank for the search term "Eventlokale" (`/eventlokale` listing, `/eventlokale/{canton}` and `/eventlokale/{canton}/{town}` landing pages, `/eventlokal/{venue-slug}` detail page, `/eventlokal-in-meiner-naehe`), without redirects (no production data), route names unchanged. "Eventlokale" is a generic German word, so it is a keyword in the path; the competitor's name, domain and logo do not appear in titles, meta, copy or structured data (CMO §8, §16). `SearchCriteria` (hotspot) gains the event type (`event[]`, OR inside the group, E5) and a guest number in place of the wedding `GuestBand` bands (D4: the guest number is the only capacity criterion); the home hero search (Kanton, Anlass, Anzahl Gäste), the filter overlays, `FilterDataQuery` and its hiding rule; the wedding filter codes and parameters (`ceremony[]`, `usage`, `price`, `curfew`, the seven wedding `amenity` codes), `_more_wedding.html.twig` and the `HomeCategory` tiles go. **Done when** the listing and the home search filter by event type and guest number, the URL codec stays stable and shareable (FI-6), the live count works, no wedding filter parameter is left, the S11 budgets and an `EXPLAIN` on the demo data ×10 hold, tests in the Integration and Application suites.

### F6 `brand-design` (L, done)

**Depends on** F1. Its brand is the brand (D23). Runs in its own lane beside F3 to F5. **Owns** no schema. **Scope.** S15: new boards in `docs/reference/public/`, tokens, logo (the SVG wordmark, D23), favicon, the mail look (`templates/emails/layout.html.twig`, class prefix `hl-`), the contrast table, the home page board, and a neutral card placeholder per event type. The sister site's brand goes. **Done when** every public page and mail runs on the new tokens, no sister-site brand asset is left in `assets/` or `public/`, the contrast table passes, `make perf-check` holds, 390 px and 1440 px screenshots match the boards, and swapping the brand means editing token, font and logo files only.

**Outcome** (merged as `98f38d7`, 2026-11-07; recorded by the PO from the PR). The wedding brand is gone. Tokens in `assets/styles/abstracts/_tokens.scss` (names unchanged): indigo primary `#3a3fb0`, vermilion accent `#e0501a`, cool neutrals; `ContrastTokensTest` green. Typeface Hanken Grotesk (OFL, one variable woff2), chosen because its widths match Figtree within 1 %, so no line wraps differently. Inline SVG wordmark (`templates/components/_logo.html.twig`), `public/brand/` with favicons, app icons, `og-default.png`, `mail-logo.png`, `site.webmanifest`; mails and the back-office accent follow the tokens. A photo placeholder per event type (`templates/components/_photo_placeholder.html.twig`) on every card, the home hero and detail pages without photos (D32). CSP gained `manifest-src 'self'`. Swap points and every file: `docs/brand.md`; guards `BrandFilesTest`, `PhotoPlaceholderTest`. The design system is also published privately as an artifact (https://claude.ai/artifact/62CFPCBUmi8R7JwJJd4woV). Left for others: `public/.htaccess`, `bin/*` and `deploy/*` still name hochzeitslocation.ch (F15); F3's final event-type codes may need a line in the placeholder's map (F5, F7b); the logo lockup, social cards, favicon set and mail header are polished in `brand-assets` (D23). The boards in `docs/reference/public/` were not redone: they still show the sister site's wedding look, so the screenshot match of the Done-when moves to F6b.

### F6b `brand-assets` (M)

**Depends on** F6. **Owns** no schema. **Scope.** Polish of the D23 brand, inside its colours and typeface: the logo lockup (wordmark with a mark, horizontal and stacked, light and dark), the OG and social cards (default card plus a per-venue and per-landing template that F7b and F8 can fill), the full favicon and app-icon set, and the mail header. The boards in `docs/reference/public/` (Canton, Detail, DetailDialogs, DetailReal, Error404, Filterbar, Guests and the rest, each at 390 and 1440 px) are rebuilt in the D23 look and replace the wedding boards; event wording on them follows F3's vocabulary. **Done when** no board shows the wedding look or wording, 390 px and 1440 px screenshots of the live pages match the new boards, every new asset is listed in `docs/brand.md` and pinned by `BrandFilesTest`, the contrast table passes, and `make perf-check` holds.

### F7a `du-flip` (S, done)

**Depends on** F0. **Owns** no schema. **Scope.** The baseline (F0) had already moved the public catalogues to "du" and turned `PublicCopyAddressTest` around (E4); F7a closed the gaps and made the guard see more than catalogues. No new copy (F7b). **Done when** no formal "Sie", "Ihr" or "Ihnen" is left in public catalogues, templates, PHP, JavaScript or the framework messages a reader can reach, the guard pins one trigger per rule, `make lint` and `make test` are green.

**Outcome** (merged as `3c19cad`, 2026-11-07). `PublicCopyAddressTest` reads every `translations/*+intl-icu.de.yaml` except `admin`, `cms` and `crm` (a new public catalogue needs no list entry), all Twig, PHP and `assets/js` as text, and the staff catalogues for a formal form inside a sentence. Formal Sie/Ihr*/Ihnen/"Sehr geehrte", plural euch/euer/"könnt ihr" and "ß" fail, and so does a capitalised "Sie" at a sentence start (write "Die Location …"); lower-case sie/ihr (she, they) and `deine-eventlocation.ch` pass. Symfony's German CSRF, upload-limit and `Length` messages say "Sie": they are overridden in `validators+intl-icu.de.yaml`, keyed by the English source text, and `tests/Integration/Shared/Translation/FrameworkMessagesTest.php` reads the sources from the framework, so a new reachable formal message needs a line there. `/konto` mails greet "Hallo {name}". Left for F7b: mixed imperative stems inside one sentence (the 41 landing intros, `home.cta.text`) and the portal rejection mail subject.

### F7b `event-copy-de` (M)

**Depends on** F3, F5, F7a (no longer F8: F8 covers the landing pages, F7b the rest). **Owns** no schema. **Scope.** All German public copy for events (the route paths moved to F5): the labels of the `setting`, `building`, `infrastructure` and `surroundings` taxonomy groups (still the sister site's after F3; PO decision 2026-11-07: F7b writes them), titles, meta descriptions and JSON-LD wording, landing texts, the home page, the legal pages (the operator D6, the processors, the contact `hallo@deine-eventlocation.ch` D9; placeholder text marked TODO until the owner delivers them), the mails, the Merkliste copy and `llms.txt`. **Done when** no wedding wording is left in public output (a test greps the rendered pages and the public catalogues for "Hochzeit" outside the event type label), titles and meta descriptions are unique, and `PublicCopyAddressTest` is still green.

### F8 `landing-event` (M)

**Depends on** F5. **Owns** `content_blocks` rows (landing texts). **Scope.** Landing pages per event type and per event type × canton, except Hochzeit (D31) (`LandingType` gains `event`; `LandingPages`, `LandingPaths` and `LandingPage::keyPrefix()` follow), their CMS texts (CMS-12), the sitemap and the JSON-LD. **Done when** the pages exist, are linked from LP-5 and the sitemap, canton and town pages still work, staff edit intro, title and meta description, tests.

### F9 `inquiry-event` (S–M)

**Depends on** F3. **Owns** the new event columns of `inquiries`. **Scope.** Event fields on the inquiry (company, event type in place of `Occasion`, layout, date and time, catering, budget), the form on the detail page (DP-13), the venue and guest mails, the "Liebes {Name}-Team" preview, the CRM inbox columns (the organizer is not a contact, S13). D3: one venue per inquiry. **Done when** an inquiry carries the event fields from form to row, mail and CRM inbox, no wedding occasion value is left, retention is unchanged, tests.

### F11 `portal-event` (S–M)

**Depends on** F4. **Owns** `portal_submissions` columns if the wizard needs them. **Scope.** Rooms and documents in `/konto` (S16 kept them with staff; for events rooms are core data) with the recipe of the photo editor, and a wizard step for the event facts in place of `WizardStep::Wedding` and `LocationCard::Wedding`. **Done when** a manager edits the rooms and documents of their own locations, the wizard asks for the event facts, staff review is unchanged, tests.

### F12 `business-model` (M–L)

**Depends on** F1. **Owns** `venues.tier` with its generated `tier_rank`, `crm_deals.tier` and the entitlement data. **Scope.** D2: a free basic entry plus paid tiers, invoiced by hand and without online payment; a lapsed paid entry falls back to Free and never goes offline; paid tiers unlock more photos, PDFs, a better ranking and visible contact data; every venue has the inquiry form. Defaults and limits are data (D14, D15). One entitlement policy is read by the detail page, `/konto` (photo limit) and the CMS (warnings). `Tier` cases, labels and the S6 ranking follow, and so do the CRM deal tier options. The rule that a new entry writes the CRM task "Rechnung stellen" after its first publish (POR-14) goes. Wave G §3 (G9) later swaps the resolver's source from `venues.tier` to `subscriptions`: design the schema so that needs no rework. **Done when** tier names and limits are data, Free and paid limits are enforced in `/konto` and the CMS, Free hides phone and website everywhere (Wave G §4a), ranking follows S6, deal tiers follow, tests.

### F13 `multi-inquiry` (M–L)

**Depends on** F9, G7. Scope and "Done when" are in Wave G §3 (`F13`, D17).

### F14 `content-onboarding` (M)

**Depends on** F3, F4, G5. **Owns** no schema. **Scope.** The three sources of D1 and the crawl of D12: managers who register in `/konto` after a mailing, the owner's spreadsheet through an import mapping into the content format, consenting hochzeitslocation customers imported once as new records with new texts, and the eventlokale crawl as internal working data (the crawler stays, D11; today it fetches only the wedding venues into `content/owner/eventlokale-hochzeit.csv`, so F14 widens it to every category). An entry goes public only after the owner has written its text and checked its facts (D12 follow-up 2, the PO's recommendation); until then it stays a draft. Public photos come live from Google Places only (D12). **Done when** each source has a documented, tested path into drafts, no eventlokale text or photo reaches a public page, the demo data or the tests, and the draft rule is enforced.

### F15 `prod-setup` (M)

**Depends on** F0 and the owner's infrastructure (D10: the host must offer PHP 8.5, Postgres 18 or Neon reached from outside, cron and SSH). **Scope.** Adapt `bin/deploy`, `.github/workflows/deploy.yaml`, `public/.htaccess`, `deploy/crontab.txt`, the backups and the Sentry monitors to the chosen host, and rewrite `docs/operations.md` (now the sister site's Hostpoint runbook). Minimal: documentation for a generic host. Deploys stay the owner's. **Done when** `make release-check` and `make deploy-test` are green and the runbook matches the host.

### F16 `launch-verification` (S–M)

**Depends on** all. **Scope.** Lighthouse and the S11 budgets (`make perf-check`, `docs/performance-baseline.md`) on every public page type, a keyboard-only walkthrough, 390 px and 1440 px, the W3C validator, `make release-check`. **Done when** every budget and score holds, and each finding is fixed or listed as a follow-up.

## Reference material

- **Product and plan:** `docs/product.md` (PO), `KICKOFF.md` (superseded fork brief), `docs/research/fork-research.md` (what carries over; written before E3, so ignore its French and language parts), `docs/prd/CMO_PRD.md` and `docs/prd/CTO_PRD.md` (their Next.js, Stripe and BullMQ parts are ignored: we stay on Symfony).
- **Behaviour of eventlokale** (a record of behaviour only, never of look or content): the screenshots in `docs/reference/*.jpg|png`, and the live listing and detail pages (see **Important** for the visit rules). Its vocabulary is public at `GET https://www.eventlokale.ch/api/guest/location/all-taxonomies`.
- **Back office:** `docs/reference/admin/` (S12). **Public look:** `docs/reference/public/` (the sister site's boards until F6).
- **Formats and runbooks:** `docs/content-format.md` with its schema, `docs/performance-baseline.md`, `docs/operations.md` (the sister site's runbook until F15).
- **Cycle history of the sister site:** `git show 7e96eee:docs/rpi-cycles.md` (**History**).

## Out of scope / proposals (not scheduled)

- **French, Italian and English** (D13): each needs `i18n-core` first (S4).
- **Online payment and a self-serve tier upgrade** (D2), a quote workflow answered in `/konto`, booking with availability, packages, boosts. Wave G §6 lists what is parked.
- **Location-Konten extensions:** inquiries in the account, 2FA for managers, a change of e-mail by the manager.
- **CRM extensions:** custom properties, inbox sync, open and click tracking, reports beyond the dashboard widgets, invoicing and payment status.
- **Back office dark theme**, a map view ("Karte") of the result list, the canton map on the home page.
- **Analytics** with consent (any analytics must stay cookieless, S17), a TypeScript or JS framework layer on the public site.
- Ratings collected on the site, provider listings, a magazine, an Award.

---

## Wave G: go-to-market cycles from the two PRDs (deine-eventlocation.ch)

_Added by the PO, 2026-11-07, approved by the tech lead the same day. Sources: `docs/prd/CMO_PRD.md` (CMO), `docs/prd/CTO_PRD.md` (CTO), `KICKOFF.md` D1–D12. Slugs F3–F16 are `KICKOFF.md` §5's cycles; G-cycles are new. Status of every row is `Todo` until its Outcome is written. This section is the master; the scratch copy in `docs/prd/wave-g-draft.md` is not kept in sync._

### Wave G §1. Decisions of 2026-11-07 (PO + tech lead; the owner delegated all decisions to them the same day)

The PRDs describe a product that is mostly ours already (free listing, paid tier, direct e-mail enquiries, claim flow). Where they conflict with a settled decision, the settled decision wins; where they add something, it goes into a cycle below. Their stack (Next.js, BullMQ, S3, Stripe) is ignored: we stay on Symfony.

| # | Decision | Feeds |
|---|---|---|
| D13 | **German only at launch (E3 stands).** The PRD's "Vier Sprachen" is not offered and not advertised. No `i18n-core` cycle. FR/IT/EN stay in Out of scope. | all |
| D14 | **Tiers: Free + Pro, CHF 199/year excluding VAT, as the default for the open "tier names and prices" input.** Invoiced by hand, no online payment (D2 stands); a tier row can be added later without code. Staff mark a subscription paid in the CRM. Stripe/TWINT is parked until the owner has a payment account. Price final (D25); VAT shown per D24. | F12, G9, G13 |
| D15 | **Free limits (data, not code):** 6 photos, 1 room, 1 video embed, 1 PDF, e-mail inquiries, monthly summary mail. **Pro (fair-use ceilings, data):** 60 photos, 20 rooms, 5 video embeds, 5 PDFs, all fitting event types, ranked above Free by tier (S6), shuffled daily within a tier, detailed monthly report, last-minute offers. Where D2 says "visible contact data": Free shows neither phone nor website; Pro shows the website and the phone number behind the click-to-reveal (DP-11: the number is never in the page HTML or the JSON-LD; only the website is in the JSON-LD). PO decision 2026-11-07. | F12, G9, G14 |
| D16 | **Prepared preview is layered on D12, not instead of it.** The D12 crawl (internal facts, never published) stays. A new importer reads a venue's **own website** (SSRF-safe) and stores facts and image **candidates**, privately, with provenance. No image is public until the claimant confirms the licence (G1). Google Places live photos (D12) cover the gap. | G3, G5, G6 |
| D17 | **Reverse match is in the run, as F13.** One brief to up to 5 disclosed venues; parent request plus child deliveries; per-venue opt-out and daily cap. D3 holds for the default flow (one venue per inquiry); multi is a second path. | F13 |
| D18 | **Honesty rules are product rules.** An unclaimed or unchecked entry is labelled "Noch nicht vom Betreiber bestätigt" and never "verified". A feature is advertised only when its entitlement flag is live: the "Features available now" matrix on the site is **derived from the entitlement data**, not typed. No named-competitor comparison page at launch (it needs dated evidence and legal review: parked). No invented counts, ratings or testimonials. | G13 |
| D19 | **Qualified enquiry** (CMO §9.2, CTO §15): a unique, real, reachable organizer, plausible event type, date and size, within the venue's declared criteria, delivered successfully, not spam, duplicate or test. It is not a booking. Stored as a derived flag plus a staff override (spam / test). Reports say "Anfragen", never "Buchungen". | G7, G8, G10 |
| D20 | **Outreach basis.** The owner's 5–6k known contacts are not assumed to be marketing-consented. The app supports a suppression list and a contact-basis field per Location; no bulk mail at launch (D30). Order of channels: postal with QR, consented contacts, one-to-one business contact, organic. | G11 |
| D21 | **Launch regions:** ZH, AG, ZG, BE get landing pages and seed focus first; other cantons stay listed. A thin or unchecked page is `noindex` and out of the sitemap until it passes a quality gate. | G12 |
| D22 | **Stack-neutral PRD items dropped:** PostGIS (we have earthdistance), Playwright fallback, perceptual-hash image dedupe, image classifier, DNS TXT verification, passkeys for owners, reply relay. Parked below. | – |
| D23 | **Brand: F6's brand is the brand** (replaces D5): indigo `#3a3fb0`, vermilion `#e0501a`, Hanken Grotesk, the SVG wordmark (`docs/brand.md`). A follow-up cycle `brand-assets` (tech lead) polishes the logo lockup, OG and social cards, favicon set and mail header within these colours and this typeface. | F6, `brand-assets` |
| D24 | **Prices are shown as final, without a VAT line:** "CHF 199 pro Jahr". The operator starts far below the CHF 100k VAT threshold, and showing VAT while unregistered creates a tax debt. The VAT line is a data flag on the tier, off; G13 reads it, so registering later needs no code. | F12, G13 |
| D25 | **Free + Pro at CHF 199 a year is final for launch** (D14 is no longer a default). | F12, G9, G13 |
| D26 | **Host** (tech lead): an Infomaniak VPS in Switzerland (php-fpm, nginx, cron, SSH, about CHF 15–30 a month), Neon Postgres 18 in `aws-eu-central-1` (Frankfurt), Infomaniak SMTP for mail; under CHF 50 a month in total. F15 targets it: a generic SSH deploy with release directories and cron, nothing Hostpoint-specific. Processors for the Datenschutz page: Infomaniak (hosting, mail), Neon (database), Sentry (EU region), Google (Maps, Places). | F15, F7b |
| D27 | **Operator facts ship as placeholders** `TODO(owner)` in the Impressum and Datenschutz pages. F16 fails while any `TODO(owner)` is left in rendered public output, and `CheckProductionEnvCommand` refuses to boot production with placeholders. A launch gate, not a question. | F7b, F16 |
| D28 | **No legal review gate.** Our texts are final, not marked draft. They follow UWG Art. 3 lit. p (an offer for a directory entry states plainly that it is paid, with price and term; the Free entry says it is free) and lit. o (no mass advertising mail without consent). | G1, G6, G11, G13 |
| D29 | **Consent wording for D1(c)** is the PO's, in `docs/onboarding-consent.md`. Hochzeitslocation sends it one-to-one to its own customers; only those who answer yes are imported, as new records with new texts. | F14 |
| D30 | **No bulk mail at launch** (tightens D20). Outreach is one-to-one personal mail and calls (the 100-account pilot) and postal mail with a QR code. G11 tracks exactly these. | G11 |
| D31 | **No Hochzeit landing pages.** "Hochzeit" stays an event type in the filter and on the detail page; F8 builds no landing page for it, alone or × canton, so the site does not compete with hochzeitslocation.ch on Google. | F8, G12 |
| D32 | **D12 follow-ups settled.** (1) A card without the venue's own photos shows F6's neutral placeholder per event type (`_photo_placeholder`); Google Places photos stay on the detail page only. (2) Crawled facts are never published as they are: an entry goes public only once its text is written and its facts are checked; until then it is a draft (F14). | F6, F14 |

### Wave G §2. PRD journey → existing module

| PRD | Where it lands | New work |
|---|---|---|
| Prebuild → preview → claim → publish (CTO J-A, CMO §6) | Portal claims, signed links, wizard, review queue (#43/#45); Venues `PublishVenue` gate; CRM crawler (#49) | G3 site importer, G5 preview link, G6 claim verification and photo approval |
| Rights-safe media (CTO F04, §13.1) | Portal `rights_confirmed_at`; Media variants | G1 grants and revocation, G2 takedown form |
| Organizer enquiry and delivery (J-B, F06) | Inquiries (mail status, stuck-inquiry cron), rate limits | G7 qualified flag, delivery alert, organizer feedback |
| Reverse match (J-C) | Inquiries, Merkliste | F13 |
| Payment and renewal (J-D) | CRM deals, `ContractPeriod`, `RenewalTasks`, `ChangeVenueTier` | F12 tier data, G9 term, expiry, downgrade, reminders |
| Analytics and monthly report (F14, CMO §9, §14) | Stats (beacon, rollup, read models) | G8 funnel events and scorecard, G10 monthly mail |
| Campaign console, suppression (CTO §17, CMO §18) | CRM Locations (prospects), tasks | G11 |
| SEO quality (CTO §11) | Search landing pages, Seo | G12 |
| Free/Pro page, supplier FAQ (CMO §18) | Cms text blocks | G13 |
| Last-minute offers (F10), video embed, brochures (F11) | Rooms, documents | G14, G15 |
| Rooms (F07) | F4 `room-layouts`, F11 | none |
| Reviews (F12 P2) | Google rating box (#53) | none |

### Wave G §3. Cycles

Sizes as in KICKOFF §5.

#### Launch-critical

Tech lead's review, 2026-11-07: dependencies cut. Wave G's own chains are at most three cycles (G3 → G5 → G6, F12 → G9 → G10); the longest chains in the file run four cycles (F3 → F5 → F8 → G12, F3 → F9 → G7 → F13, F3 → F4 → F11 → G14). F7b waits only for F3, F5 and F7a. Lanes are the tech lead's (§4).

| # | Slug | Size | Depends on | Lane | Schema (owner of) |
|---|---|---|---|---|---|
| F7a | `du-flip` | S | F0 | G | – (done, `3c19cad`: the baseline had already flipped the catalogues; F7a hardened `PublicCopyAddressTest`) |
| – | `rules-condense` | S | fork-docs | A | – (module notes down to current state) |
| F7b | `event-copy-de` (was F7) | M | F3, F5, F7a | A | – |
| G1 | `rights-grants` | M | F0 | E | `media_rights_grants` |
| G2 | `takedown-form` | S | G1 | E | `moderation_cases` (Venues; queue screen in Cms) |
| G3 | `site-import` | M | F0 | F | `source_records`, `photo_candidates`; fetcher in `Shared\Http` |
| G5 | `preview-link` | M | G1, G3 | F | `venue_previews` |
| G6 | `claim-verify` | M | G1, G3, G5 | E | `claim_challenges` (or on `portal_venue_access`) |
| G7 | `enquiry-quality` | S | F9 | G | `inquiries.qualification` |
| G8 | `funnel-scorecard` | M | F0 | G (early) | `funnel_events` (Stats) |
| G9 | `subscription-term` | M | F12 | D | `subscriptions` |
| G10 | `owner-report` | M | G8, G9 | D | – |
| G11 | `outreach-tracking` | M | G5, F12 | G | `suppressions`; Location: campaign, contact basis |
| G12 | `launch-regions` | S–M | F8 | B | – |
| G13 | `tier-pages` | S | F12, G9, F7a | A | – |

#### After launch-critical (in the run, last)

| # | Slug | Size | Depends on | Lane | Schema (owner of) |
|---|---|---|---|---|---|
| F13 | `multi-inquiry` (reverse match, D17) | M–L | F9, G7 | G | `inquiries` (parent and child deliveries), per-venue opt-out and cap |
| G14 | `last-minute-offers` | M | F11, G9 | D | `last_minute_offers` |
| G15 | `video-embed` | S | F12 | E | `venue_videos` |
| G16 | `organizer-feedback` | S | G7 | G | `inquiry_feedback` |

F14 `content-onboarding` now follows G5 (lane F). F15 is minimal (docs for a generic host), F16 last.

#### Scope

**G1 `rights-grants`.** A grant record per published owner photo: grantor (manager account), scope (listing, resized copies, derivatives), evidence (upload, declaration, policy version), timestamps, revoked_at. A photo uploaded in `/konto` or the CMS needs a grant to go public; the `/konto` submission's `rights_confirmed_at` becomes the policy-versioned declaration. Revoking unpublishes the photo and purges the listing cache. Policy text is a versioned content block. _Done when:_ no public photo without a grant or a Google Places source; revoke removes the photo from every page and cache; migration `down()`; tests.

**G2 `takedown-form`.** Public form (no account) for a rights, removal or correction complaint, linked from the footer and every detail page. `moderation_cases` lives in Venues (Cms may depend on Venues, not on Inquiries); the staff queue is a Cms screen and lists cases; a credible rights complaint unpublishes the disputed photo (or the entry) until decided; decision, provenance and timestamps are kept; sitemap and caches update. _Done when:_ a complaint to unpublish round-trips in under a minute in a test; audit rows; a mail to the complainant and to staff.

**G3 `site-import`.** Starts on F0 alone: it writes only `source_records` and `photo_candidates`, with no vocabulary mapping. `app:venues:import-site <url>`: SSRF-safe fetcher in `Shared\Http` (http/https only, DNS resolved on every redirect, private, loopback, link-local and metadata ranges refused, size, time, redirect, page and image caps, same-host pages only, robots.txt honoured), a parser for canonical, OG, JSON-LD `LocalBusiness`, address, phone, headings, `img`/`srcset`, relevant pages (`/event`, `/seminar`, `/raeume`, `/galerie`). Output: facts with confidence and source URL, image **candidates** (stored private, never served), all in `source_records`/`photo_candidates`. No text is copied into a public field. _Done when:_ a hostile-URL test set (127.0.0.1, `169.254.169.254`, redirect to private, rebinding stub) is refused; 10 fixture sites yield facts; candidates are not reachable by any public route. **Outcome.** `app:venues:import-site <url> [--venue=<slug>]` (`Venues\Console\ImportSiteCommand`) reads a venue's own website and stores one `source_records` row with its `photo_candidates`. Nothing is published, no venue is created, no image is downloaded and no vocabulary is mapped. **Fetcher:** `Shared\Http\SafeFetcher::open()` returns a `FetchSession` (one crawl, its own budget). Http and https on ports 80 and 443 only; `SafeUrl` rebuilds every URL from its parts and refuses credentials, control characters, backslashes, IP-address hosts in any spelling, one-label names and `.local`, `.internal`, `.localhost`. Every request resolves the host again (`HostResolver`, `DnsHostResolver` asks A and AAAA only), refuses the whole answer if any address is not public (`AddressPolicy`: IPv4 special ranges, CGNAT, cloud metadata addresses; IPv6 only `2000::/3` minus protocol, documentation and 6to4; NAT64 and IPv4-mapped addresses refused) and connects to one vetted address through the client's `resolve` option; `no_proxy` is `*`, and a connection to an address that never passed the check in this crawl is aborted through `primary_ip` (a reused keep-alive connection to another vetted address is fine). Requests send `Accept-Encoding: identity`. The client never follows a redirect: each `Location` is vetted like a new URL, at most 3. After the first page everything stays on its site (`www.` twin allowed). robots.txt is read once per host and compared in one spelling (percent-encoding normalised, RFC 9309; 4xx means none; an unreadable file, or one with a rule over 300 bytes or more than 1,000 rules for us, ends the crawl of that host; `Crawl-delay` is honoured and above 10 s refuses the host), with at least 1 s between two requests to a host. `FetchLimits` caps a crawl: 1.5 MB per response after decompression, 6 MB, 8 pages, 90 s, 15 s per request, 8 s idle, content types `text/html` and `application/xhtml+xml`. A refusal is `Shared\Exception\FetchRefused` with a `RefusalReason`; all text from a site passes `Shared\Http\PlainText` (valid UTF-8, no control, format or private-use characters) before it is stored, printed or put in a refusal. `FetchPolicy::unsafeForLocalFixtures()` is for tests and throw-away scripts only. Any later fetch of an address a person typed goes through `SafeFetcher`, never a bare `HttpClientInterface`. **Importer (`Venues\SiteImport`):** `SiteImporter` reads the entry page and up to seven same-site pages whose path or link text says rooms, events, seminars, gallery, weddings, contact or imprint. `SitePageParser` (DomCrawler; `symfony/dom-crawler` and `symfony/css-selector` moved from `require-dev` to `require`, `ext-curl` declared) reads canonical, Open Graph and Twitter tags, JSON-LD (`LocalBusiness`, `EventVenue`, `Hotel`, `Restaurant` ..., also inside `@graph`), `tel:` and `mailto:` links, `<address>`, microdata and text lines for phone, e-mail and Swiss addresses, headings and capacity snippets, and image references from `img` (lazy attributes, `srcset`, `picture`), CSS backgrounds, `og:image` and `link rel=image_src`. Icons, logos, svg, gif and pictures declared under 200 x 120 are counted and left out; 80 references per page and 60 per site are kept, and the same photo in two sizes counts once. A page that nests elements more than 300 levels deep is not parsed (`Venues\Exception\UnreadablePage`: a later page is reported as refused, the entry page stops the import), because DOM building time grows with the square of the depth. **Tables** (migration `Version20261107141722`; `BaselineMigrationTest` now runs every migration's `down()` newest first and `up()` oldest first, so later migrations with foreign keys to baseline tables no longer break it): `source_records(venue_id NULL, source_type 'official_website', url, status 'fetched'|'superseded', facts JSONB, report JSONB, fetched_at)` and `photo_candidates(source_record_id, image_url, page_url, origin, alt, context, width, height, bytes, position, status 'candidate'|'approved'|'rejected')`, both `ON DELETE CASCADE`. `url` is the normalised address, and a partial unique index allows one `fetched` record per URL: a later import supersedes the earlier record and keeps it; `facts` is `[]` when a page yielded nothing. G3 writes `candidate` only (G6 approves or rejects) and never fills `bytes`. `facts` is key to a list of observations `{value, confidence, source_url, via}`, best first: `name`, `description`, `canonical_url`, `website`, `language`, `type`, `address` (`street`, `postal_code`, `locality`, `country`), `phone` (+41 format), `email`, `geo`, `capacity`, `heading`, `snippet`, `page`. **Private by rule:** `Architecture\PrivateImportDataTest` keeps controllers, read models, Twig, forms, mailers, listeners and extensions away from the two entities and `SiteImport`, `SiteImportIsPrivateTest` walks every public route and finds no imported value, `PrivateImportDataProbeTest` proves the rule fires, and `make seed` loads one demo record. **Tests:** the hostile-URL set and the caps are in `tests/Unit/Shared/Http`, ten fixture sites in `tests/Fixtures/SiteImport` (`SiteFixturesTest`; the format is in the test), and `tests/Integration/Shared/Http/SafeFetcherTransportTest` runs the real cURL transport against a throw-away `php -S` server (pin, no client redirect, endless, silent and dripping servers, proxy variable). **Decisions and deviations:** no image bytes are fetched, not even a HEAD; a DNS answer that mixes public and non-public addresses is refused as a whole; the first page may redirect to another public host, which then is the site; there is no staff panel on the CMS venue entry (not cheap; the command report and SQL show the data), a candidate for G6 or F14. **For G5, G6 and F14:** read the latest `fetched` record of a venue, treat every URL in `facts` and `photo_candidates` as untrusted, and fetch one only through `SafeFetcher`.

**G5 `preview-link`.** A prepared draft can be opened through a signed, expiring link without an account (`noindex`, no personal data in the URL, safe to print as a QR). The page shows the entry as it would look, the banner "Noch nicht vom Betreiber bestätigt", the CTA "Gratis übernehmen" and "Angaben korrigieren". Photos show only if their rights basis covers it (Google Places live photo or granted); otherwise the neutral placeholder (D12 follow-up 1). Each link carries a campaign code; opening it records `venue_preview_opened`. Staff issue and revoke links in the CMS entry. Publishing stays behind F14's rule (text written, facts checked), which is a note here, not a dependency. Photo visibility comes from the photo-policy service that G1 defines. Emits `venue_preview_opened` (G8). _Done when:_ link open without login; expired and revoked links answer 410; no preview in sitemap, search or cache.

**G6 `claim-verify`.** "Gratis übernehmen" starts a claim. Proof by a code sent to an address on the venue's own website domain (generic addresses go to the staff queue of #43 as today); then the claimant sees the photo candidates from G3, ticks those to publish, gives the declaration (G1), confirms contact data and the inquiry address, and submits through the existing submission and review path. Repeat or competing claims on one venue freeze edits and go to staff. _Done when:_ an unrelated address cannot claim; two claims on one venue reach staff; publication needs a grant per photo; mail proof of publication with settings link and removal link. #43's staff queue stays the fallback for generic addresses and the target for competing claims. Emits `claim_started`, `claim_verified`, `venue_published` (G8).

**G7 `enquiry-quality`.** Emits `inquiry_delivered` (G8). Derived `qualification` on each inquiry (`qualified`, `unqualified`, with a reason; staff can mark `spam`/`test`), per D19; delivery status visible in the CRM inbox; the stuck-inquiry cron mails staff on a failure; the guest sees a clear failure state, no silent loss. _Done when:_ a unit-tested rule; the CRM inbox shows delivery and qualification; counts exclude spam and test.

**G8 `funnel-scorecard`.** Depends on F0 only and runs early: the table `funnel_events` (owned by Stats; event-level rows with `occurred_at`, `venue_id`, `campaign_code`, no personal data, because Stats rollup rows are aggregates and cannot do cohorts or weeks), a Shared command `RecordFunnelEvent` with a Stats handler (Crm may not depend on Stats, Portal may), and the scorecard shell. The emitting cycles add their own events in their "done when": G5 `venue_preview_opened`, G6 `claim_started`/`claim_verified`/`venue_published`, G7 `inquiry_delivered`, G9 `pro_paid` (staff-marked) and `subscription_cancelled`, F13 `reverse_match_completed`. A `/admin` scorecard (CMO §9.1, §17): the stages above per campaign and week, qualified enquiries, paid venues with at least one qualified enquiry ÷ paid venues, founder minutes per claim (manual field). _Done when:_ the command writes a row; a seeded funnel renders; no event carries an e-mail address; query ceiling noted; the architecture rules allow Portal and Crm to dispatch the Shared command.

**Outcome** (PR `funnel-scorecard`, 2026-11-07). Table `funnel_events(id, occurred_at, event, venue_id, campaign_code)` (Stats, migration `Version20261107150513`; no foreign key on `venue_id`; CHECKs pin the event list and a plain-code campaign pattern, so an e-mail address cannot be stored). **Emit a step with the Shared command `App\Shared\Command\RecordFunnelEvent(FunnelEvent $event, ?string $venueId = null, ?string $campaignCode = null)`** on `command.bus`; `FunnelEvent` is `App\Shared\Funnel\FunnelEvent`: `PreviewOpened` (`preview_opened`, G5), `ClaimStarted`, `ClaimVerified` (G6), `VenuePublished`, `InquiryDelivered`, `ProPaid`, `SubscriptionCancelled` (G9), `BriefSent` (`brief_sent`, F13: the scope text's `venue_preview_opened` and `reverse_match_completed` are these two names). Inside a command handler dispatch with `[new DispatchAfterCurrentBusStamp()]` (the row is written after your commit and dropped if your command fails); from a controller or console command dispatch plainly. The Stats handler is best effort and never throws: it drops (and logs, without the value) a venue id that is no UUID or a campaign code that is not a plain `App\Shared\Funnel\CampaignCode`, still records the step, and takes the time from the clock. `campaignCode` is optional: the scorecard gives a step the first campaign recorded for its venue before it; validate staff-entered codes with `CampaignCode::isValid()` and never take one from a visitor's request. **Already emitted, do not emit again:** `venue_published` by `PublishVenueHandler` (every transition to online) and `inquiry_delivered` by `DeliverInquiryHandler` (once per inquiry, after the venue mail went out); G6 and G7 add their own steps only. Code that calls `Venue::publish()` directly (the content import) emits nothing. A new `FunnelEvent` case needs a migration replacing `chk_funnel_events_event` (`StatsVocabularyChecksTest` fails until then). **Screen:** `/admin/statistik/funnel` (`admin_stats_funnel`, `ROLE_EDITOR`, button on `/admin/statistik`): the eight steps per ISO week (Europe/Zurich; `?wochen=` 4/12/26/52, default 12) and per campaign (`?kampagne=`), venue steps counted as distinct venues, "Zahlende Locations" (a `pro_paid` in the last 365 days) with a delivered inquiry since the payment; the qualified figures show "n/a" until G7 fills `FunnelPaying::withQualifiedInquiry`. Seeds: `FunnelStory`, `RecordedFunnelEventFactory`. **Query ceiling:** four statements; with the 52-week window holding all rows, about 0.5 s at 30,000 rows and 3 s at 300,000 (expected volume is tens of thousands a year, the default window is 12 weeks); past that add a weekly rollup like `stats_daily` (details in `module-stats.md`). **Deviation:** the manual "founder minutes" field is not built (it needs a table, a command and a form; G10 or on request). `BaselineMigrationTest` now also replays every later migration, so no cycle edits it for its own table; a test that commits rows deletes its `funnel_events` rows. `ModuleBoundariesProbeTest` has clean Crm and Portal fixtures that dispatch the Shared command, which proves both may.

**G9 `subscription-term`.** Builds on F12's tier and entitlement data: F12's resolver reads `venue.tier`, G9 swaps its source to `subscriptions` (same lane, same teammate; F12's researcher gets this spec so the schema needs no rework). Term start and end, staff mark paid (from the CRM deal), nightly expiry: a lapsed Pro entry drops to Free and **stays online**; reminders to the manager 30 and 7 days before the end (mail with price, cancellation link, what stops); cancellation by link keeps service to term end; the entitlement check reads the term, not copied flags. The CRM only reminded before (#28); now it also acts. _Done when:_ clock-driven tests for expiry and reminders; the second run of the cron writes nothing; photos beyond the Free limit are hidden, not deleted.

**G10 `owner-report`.** Monthly mail per entry to the manager (Free: basic; Pro: detailed): visits, engaged views, enquiry starts, delivered enquiries versus the previous month, one concrete correction suggestion (missing photo, fact or room), the renewal date, an edit link, a one-click opt-out. Uses Stats read models; no booking claims. _Done when:_ idempotent cron; a venue with no data gets an honest mail; the opt-out is honoured.

**G11 `outreach-tracking`.** On a CRM Location: campaign code, cohort (A–D, CMO §6.5), contact channel and basis, send date, returned mail, objection. A `suppressions` table that every export and mail path respects. A mail-merge/print export of preview links with QR short links, which is a CSV, never a mailer. Read model: funnel by cohort (G8). _Done when:_ a suppressed Location is absent from every export; the cohort funnel renders.

**G12 `launch-regions`.** Landing pages and seed focus for ZH, AG, ZG, BE; an indexation gate: an entry is `noindex` and out of the sitemap until its facts are checked and its text written; a landing page is indexable only above a venue threshold (data); zero-result searches are logged (query and filters, no person) and shown weekly in the scorecard. _Done when:_ a thin page is `noindex`; the sitemap matches the gate; the zero-result list renders.

**G13 `tier-pages`.** Waits for the du-flip (F7a), not for F7b. Public "Eintragen / Preise" page: Free vs Pro matrix **generated from entitlement data** (D18), price with VAT line, FAQ (rights, who may claim, cost, cancellation, removal, direct e-mail), a link to claim. No competitor named. _Done when:_ changing an entitlement changes the page; a test fails if the page lists a flag that is off.

**F13 `multi-inquiry` (reverse match, D17).** From the Merkliste or a result list: one brief to 3–5 venues, each named on the consent step; one parent request and one child delivery per venue (own ID, own mail status); per-venue opt-out and daily cap; the organizer gets one confirmation; counts as one organizer in reports. _Done when:_ exactly the disclosed recipients get mail; an opted-out venue is never offered; a cap is enforced; the funnel counts one brief once.

**G14 `last-minute-offers`.** Pro only. Date or period, room, event fit, indicative price or package text, minimum guests, terms, expiry. The manager sets it in `/konto` or by a signed mail link. Expired offers vanish by cron; the listing shows a badge; a "Kurzfristig frei" list page; text states that availability needs venue confirmation. _Done when:_ no past dates; expiry tests; Free venue cannot create one.

**G15 `video-embed`.** YouTube/Vimeo embeds by URL allowlist, click-to-load (no third party before consent, keeps the cookieless promise and the CSP), limits from entitlements. _Done when:_ a non-allowlisted URL is refused; no request to the video host before click.

**G16 `organizer-feedback`.** One optional mail 7 days after a delivered inquiry: did the venue answer, did it work out. Stored per inquiry, shown to the venue's report as "x Rückmeldungen", never as bookings. _Done when:_ one mail only; opt-out link; no mail for spam or test.

### Wave G §4. Order and lanes (tech lead, 2026-11-07)

Six or seven concurrent lanes.

```
A docs         fork-docs → rules-condense → F7b → G13
B vocab/search F3 → F4 → F5 → F8 → G12
C brand        F6
D business     F12 → G9 → G10 → G14
E rights/claim G1 → G2 → G6 → G15
F import       G3 → G5 → F14
G inquiry      F7a → G8 → F9 → G7 → F11 → G16 → F13 → G11
Status 2026-11-07: done fork-docs, F7a. Running F3, F12, G1, G3, G8. rules-condense next. F6 finished, blocked on a git permission.   F15 minimal, F16 last.
```

Critical path to a sellable first Pro: F12 → G9 → G13, in parallel with G1 → G3 → G5 → G6 (claim). G14–G16 and F13 do not block launch. State lives in docs and git (`.rpi-tracking/lead/state.md` in the main checkout), not only in messages.

### Wave G §4a. Cross-cutting acceptance (every Wave G cycle; tech lead)

1. An entitlement or tier change (G9 expiry, staff change, downgrade) purges the HTTP and listing cache of that entry (S10).
2. Free hides phone and website (D15) everywhere: card, detail page, JSON-LD, meta, Merkliste and mails. Pro shows the website; the phone number stays behind the click-to-reveal and out of the HTML and JSON-LD (DP-11). Tests read the rendered HTML source, not only the template.
3. Hidden-not-deleted photos: the visible set is deterministic (the first N by sort order); staff keeps the order.
4. Preview and claim pages: no HTTP cache, no stats beacon, `X-Robots-Tag: noindex`, the token is never logged.
5. Every new nightly job goes into `deploy/crontab.txt` with a Sentry cron monitor and a note in `module-shared`, and a second run writes nothing.
6. Every new mail uses the `templates/emails` layout and `EmailHtmlToTextConverter`, is written in "du", and carries an opt-out link when it is not transactional.
7. The Foundry stories (`DemoStory`, `CrmStory`, `PortalStory`) get the new entities, so `make seed` shows each feature.
8. One hand-written migration per cycle, with `down()`, named from the wall clock; no baseline edits.
9. The S11 byte budgets still hold on the detail page after badges, banners and offers (`make perf-check`).

### Wave G §5. Product claims the marketing may make (CMO §19, adapted)

| Claim | Needs shipped | Allowed when |
|---|---|---|
| "Dein Eintrag ist vorbereitet" | G3, G5 | That venue has a preview |
| "Gratis übernehmen" | G6 | Claim round trip works |
| "Anfragen direkt per E-Mail" | existing Inquiries, G7 | Delivery tests pass |
| "Alle passenden Räume" | F4, F11 | Room-level page live |
| "Pro CHF 199/Jahr" | F12, G9, G13 | Price and VAT line match the invoice |
| "Last-Minute-Angebote" | G14 | Live |
| "Eine Anfrage, bis zu fünf Locations" | F13 | Live |
| "Vier Sprachen", "Marketingvideo", "weitere Portale" | – | Not offered (D13, parked) |
| "Keine Provision", "Kein neues System nötig" | – | True by design |

### Wave G §6. Parked (documented, not scheduled)

Online payment (Stripe/TWINT), promo-video renderer and social kit, partner syndication, reviews of our own, FR/IT/EN, passkeys for owners, DNS-TXT claim proof, reply relay, Playwright fallback for JavaScript sites, perceptual-hash dedupe and an image classifier, a named-competitor comparison page (needs dated evidence and legal review), PostGIS, a venue-facing mini-CRM, availability calendar sync.

### Wave G §7. Facts only the owner holds (each has a placeholder, so no cycle blocks)

Every former owner decision is settled in D23 to D32. What is left are facts:

| Fact | Until it arrives | Needed before |
|---|---|---|
| Operator name and address | `TODO(owner)` placeholder; F16 and `CheckProductionEnvCommand` refuse it (D27) | launch |
| Infomaniak and Neon accounts (D26) | F15 documents the setup | first deploy |
| Spreadsheet for D1(b) | F14's import path is tested on a fixture | F14 import run |
| Contact list for the one-to-one pilot (D30) | – | after launch |
