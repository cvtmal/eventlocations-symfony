# Hochzeitslocation: RPI cycles

_Last updated: 2026-10-30 (#0 project-skeleton, #1 design-system, #2 i18n-foundation, #3 geo-reference-data, #4 venue-schema, #5 venue-media, #6 search-query, #7 listing-page, #8 venue-detail-page, #9 venue-content-import, #10 filter-region, #11 filter-guests, #12 filter-more, #13 merkliste, #14 inquiry-form, #15 venue-map, #16 seo-foundation, #17 seo-landing-pages, #18 legal-pages, #21 legacy-data-import, #22 admin-foundation, #23 admin-ui-kit, #31 venue-wedding-facts, #32 owner-content-import and #33 filter-wedding done; owner answered Q1, Q2, Q4–Q12; back office (CMS #22–#26, CRM #27–#30) added; owner data from deine-hochzeitslocation.ch analysed, S14 and #31–#33 added, Q13 and Q14 open; production content is the Wix CSV only, venues without addresses accepted) · Source: this file (scope, requirements, order), `docs/reference/` (screenshots of the original), `docs/reference/admin/` (back-office design reference)_

We are building a Swiss wedding-venue listing site: one listing page with filters, one detail page per venue, and a Merkliste that needs no login. It copies the layout, style and content structure of eventlokale.ch's wedding search. It is **not** an eventlokale clone: there is no magazine, no providers, no event types, no Award.

Behind it sits a small **back office** for our own staff at `/admin` (S12): a **CMS** to manage venues, photos, rooms, documents and landing-page texts, and a HubSpot-style **CRM** for the venue operators we sell tiers to and the couples who send inquiries. Both are lightweight and minimalist, and open for extensions. The CMS goes live with the site; the CRM follows after launch (Q8).

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
- Never copy eventlokale's texts, photos, logos, icons or font files into this repository, the demo data or the tests. We copy layout, structure, interaction and colour values. Reference screenshots stay in `docs/reference/` as documentation only. The one exception is the venue content from the owner's Wix CSV (#21, Q12). It lives in `CONTENT_ROOT` / `MEDIA_ROOT` outside the repository and is never committed. The owner data (S14, #32) stays outside the repository in the same way.
- Every UI cycle compares its result with the matching screenshot in `docs/reference/` at 390 px and 1440 px width, using Claude in Chrome against the local app. The live eventlokale page may be visited for behaviour questions: slowly, at most about 20 requests per session (Cloudflare rate-limits at ~35 rapid requests), never with forms submitted.
- Every back-office cycle (#22–#30) compares its screens with the design reference in `docs/reference/admin/` (S12) at 1440 px, and checks that they stay usable at 390 px. The reference is the target; eventlokale is not.
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
   | `tests/Architecture/ArchitectureRules.php` (module table) | #0 declares **all** modules of this file up front | nothing; a needed change goes back to the owner as a design change. S12 is such a change: #22 adds `Admin`, `Cms` and `Crm` (next row) |
   | `templates/base.html.twig`, `templates/layout/*` | #1 | fill the blocks #1 provides |
   | `assets/styles/app.scss` (list of partial imports) | #1 | append one `@use` line for their own partial |
   | `config/packages/translation.yaml`, `src/Shared/Translation/*` | #2 | nothing |
   | `translations/messages+intl-icu.de.yaml` (layout strings) | #1 | nothing; each module has its own domain file |
   | `src/Search/ReadModel/SearchCriteria.php` and its URL codec | #6 | nothing; filter UIs (#10–#12) only render it. #33 adds the S14 parameters once (a design change, like S12's) |
   | `templates/search/_filter_bar.html.twig` | #7 | fill their own `{% include %}` slot |
   | `CLAUDE.md` | #0 | append to their module's section |
   | `tests/Architecture/ArchitectureRules.php` `MODULES` and `ModuleBoundariesTest` | #22 adds `Admin`, `Cms` and `Crm` once (the design change of S12) | nothing |
   | `config/packages/security.yaml` | #22 | nothing; guard routes with `#[IsGranted]` |
   | `templates/admin/base.html.twig`, `templates/admin/layout/*`, `assets/styles/admin.scss` (list of partial imports) | #22 | fill the blocks; append one `@use` line for their own partial |
   | `templates/admin/components/*`, `admin-ui/src/components/*`, `templates/admin/form/theme.html.twig` | #23 | use them; a missing variant goes into their own module folder first |
   | `admin-ui/src/islands.js` (island registry), `admin-ui/src/timeline/types.js` (timeline renderers) | #22 / #27 | append one line per island or activity type |
   | `admin-ui/package.json`, `package-lock.json` | #22 | add a dependency only with a one-line reason in the Outcome; on a rebase conflict, take `main`'s lock file and run `make admin-install` again |
   | `translations/admin+intl-icu.de.yaml` (shell strings) | #22 | nothing; CMS strings go to `cms`, CRM strings to `crm` |

6. **Concurrency.** Three to four sessions at a time is the sweet spot. More than that and the rebase step and the owner's reviews become the bottleneck.

## Overview and order of execution

| # | Slug | Wave | Requirements | Depends on | Schema ownership | Gate | Start at |
|---|---|---|---|---|---|---|---|
| 0 | `project-skeleton` | W0 | OP-1, GEN-2 | — | — | — | ✅ Done |
| 1 | `design-system` | W1 | GEN-3, A11Y-1, PERF-1 (CSS, fonts) | 0 | — | — | ✅ Done |
| 2 | `i18n-foundation` | W1 | GEN-1, SEO-6 | 0 | — | — | ✅ Done |
| 3 | `geo-reference-data` | W1 | FI-1 (data), FI-2 (data) | 0 | `cantons`, `towns` | — | ✅ Done |
| 4 | `venue-schema` | W2 | DATA-2, DP-5–DP-15 (data), LP-2 (data) | 2, 3 | `venues`, `venue_*`, `rooms`, `room_*`, `taxonomy_terms` | — | ✅ Done |
| 5 | `venue-media` | W3 | PERF-2, LP-3 (images), DP-2 (images) | 4 | `venue_photos` (columns for variants) | — | ✅ Done |
| 6 | `search-query` | W3 | LP-4, FI-5, FI-6, PERF-3 (query) | 4 | indexes only | — | ✅ Done |
| 7 | `listing-page` | W4 | LP-1–LP-3, LP-5, LP-6 | 1, 5, 6 | — | — | ✅ Done |
| 8 | `venue-detail-page` | W4 | DP-1–DP-12, DP-14–DP-16 | 1, 5, 6 | — | — | ✅ Done |
| 9 | `venue-content-import` | W4 | DATA-1 | 4, 5 | `content_imports` | — | ✅ Done |
| 10 | `filter-region` | W5 | FI-1, FI-2 | 3, 7 | — | — | ✅ Done |
| 11 | `filter-guests` | W5 | FI-3 | 7 | — | — | ✅ Done |
| 12 | `filter-more` | W5 | FI-4 | 7 | — | — | ✅ Done |
| 13 | `merkliste` | W5 | MK-1–MK-4 | 7, 8 | — | — | ✅ Done |
| 14 | `inquiry-form` | W5 | DP-13, IQ-1–IQ-4 | 8 | `inquiries` | ~~Q6~~ (to the venue) | ✅ Done |
| 15 | `venue-map` | W5 | DP-10 (map) | 8 | — | Q3 (production key only) | ✅ Done |
| 16 | `seo-foundation` | W6 | SEO-1–SEO-3, SEO-5, SEC-1 | 7, 8 | — | — | ✅ Done |
| 17 | `seo-landing-pages` | W6 | SEO-4, LP-5 | 10, 16 | `landing_pages` (if needed) | — | ✅ Done |
| 18 | `legal-pages` | W6 | LEG-1, LEG-2 | 1, 13, 14 | — | ~~Q5~~ | ✅ Done |
| 19 | `performance-caching` | W7 | PERF-1–PERF-3, ADM-6 | 10–17, 24, 25, 33 | — | — | Research |
| 20 | `prod-deploy` | W8 | OP-2, OP-3, OP-4 | 19, 21, 26, 32 | — | ~~Q1~~, ~~Q2~~ (Hostpoint + Neon), Q14 (redirects only) | Research |
| 21 | `legacy-data-import` | W5 | DATA-3 | 3, 9 | `venues`: address, town, canton and coordinates become optional; `venue_translations.region` | ~~Q4~~, ~~Q12~~ | ✅ Done |
| 22 | `admin-foundation` | W4 | ADM-1–ADM-6, ADM-8 | 1, 2 | `admin_users`, `admin_audit_log` | ~~Q9~~ | ✅ Done |
| 23 | `admin-ui-kit` | W5 | ADM-4, ADM-7 | 22 | — | ~~Q11~~ | ✅ Done |
| 24 | `cms-venues` | W6 | CMS-1–CMS-4, CMS-9–CMS-11 | 8, 9, 21, 23, 31 | `venues.cms_edited_at` | — | Research |
| 25 | `cms-venue-media` | W6 | CMS-5–CMS-8 | 9, 23 | — | — | Research |
| 26 | `cms-pages` | W7 | CMS-12 | 17, 23 | `content_blocks` (+ columns on #17's `landing_pages`, if it exists) | — | Research |
| 27 | `crm-foundation` | W9 | CRM-1–CRM-6, CRM-11 | 20, 24 | `crm_companies`, `crm_contacts`, `crm_company_venues`, `crm_activity_*`, `crm_activities`, `crm_tasks` | ~~Q8~~, ~~Q10~~ | Research |
| 28 | `crm-deals` | W10 | CRM-7, CRM-8 | 27 | `crm_deal_stages`, `crm_deals`, `crm_deal_contacts`, `crm_activity_deals` | — | Research |
| 29 | `crm-inquiries` | W10 | CRM-9, CRM-11 (couples) | 14, 27 | `crm_inquiry_links` | — | Research |
| 30 | `crm-email` | W10 | CRM-10 | 27 | `crm_email_templates`, `crm_emails` | — | Research |
| 31 | `venue-wedding-facts` | W5 | DATA-4, DP-17 | 8, 9 | new columns on `venues` and `venue_translations`; new `taxonomy_terms` groups and rows | — | ✅ Done |
| 32 | `owner-content-import` | W6 | DATA-5 | 9, 21, 31 | — | ~~Q12~~, Q13, Q14 (tier only) | ✅ Done |
| 33 | `filter-wedding` | W6 | FI-7, LP-7 | 12, 31 | indexes only, if needed | — | ✅ Done |

### Waves at a glance

```
W0  #0 project-skeleton
W1  #1 design-system  ‖  #2 i18n-foundation  ‖  #3 geo-reference-data
W2  #4 venue-schema                                   (#1 may still be running)
W3  #5 venue-media  ‖  #6 search-query                (#1 may still be running)
W4  #7 listing-page  ‖  #8 venue-detail-page  ‖  #9 venue-content-import  ‖  #22 admin-foundation
W5  #10 filter-region ‖ #11 filter-guests ‖ #12 filter-more ‖ #13 merkliste ‖ #14 inquiry-form ‖ #15 venue-map ‖ #21 legacy-data-import ‖ #23 admin-ui-kit ‖ #31 venue-wedding-facts
W6  #16 seo-foundation  ‖  #18 legal-pages   →   #17 seo-landing-pages (needs #16)
    #24 cms-venues  ‖  #25 cms-venue-media                (back office, parallel to the SEO lanes)
    #32 owner-content-import  ‖  #33 filter-wedding       (owner data, parallel to both)
W7  #19 performance-caching  ‖  #26 cms-pages
W8  #20 prod-deploy                                       (launch: site + CMS)
W9  #27 crm-foundation                                    (after launch)
W10 #28 crm-deals  ‖  #29 crm-inquiries  ‖  #30 crm-email
```

W5 holds nine cycles; with three to four lanes it runs in two or three rounds. Start #14, #21 and #31 early in W5, because #29, #20, #24, #32 and #33 wait for them.

**Critical path:** #0 → #2/#3 → #4 → #6 → #7 → #10 → #17 → #19 → #20. #1 runs alongside W1–W3 and must be done before W4. #9, #21, #31 and #32 are off the critical path until #20, which needs the real content; demo data from #4/#5 carries every UI cycle. The back-office path #22 → #23 → #24/#25 → #26 runs beside it and joins at #20; #26 waits for #17, so it is as long as the SEO path.

**Milestones.**
- After #8: you can browse the listing and detail pages locally with demo data. This is the first owner review of look and feel.
- After #21: the local app runs on the 243 real venues (base data; see the source change in #21). This is the second owner review.
- After #32: the local app runs on the 243 venues with the owners' own texts, photos, prices and wedding facts. This is the owner review of the data merge.
- After #15: the MVP is feature-complete (all filters, Merkliste, inquiry, map).
- After #25: the owner can edit every venue field, photo, room and document in the CMS locally. This is the owner review of the back office.
- After #19 and #26: launch-ready (SEO, legal pages, performance budgets met, CMS complete).
- After #20: live on hochzeitslocation.ch, and the database is the content master (S8).
- After #27: the CRM is live, with companies built from the venues.
- After #28–#30: the CRM is complete (pipeline, inquiries, e-mail).

**What can move.** #9 can run any time after #5. #21 can run any time after #9, and the earlier the better, because #17's landing-page thresholds and #19's measurements should use the real data. #15 and #18 have nothing depending on them except #19 and can slip. #11 is small and can be folded into #12 if a lane is short of work. #22 needs only W1, so it can start before the other W4 cycles. #23 can start as soon as #22 is done, without waiting for W4. #27's research and plan may run during W8, but it is merged only after #20 is `Complete` (Q8). #30 needs #20's mail setup (SPF/DKIM) only for its production check; it can be built before that. #31 can start as soon as #9 lands. #32 is re-runnable, so it can run early on the snapshot and again on a fresh export before #20. #33 can slip until after #32, when its filters have data worth testing.

## Owner gates

Each gate has a default, so no cycle is blocked as a whole. The owner answers here and the answer is recorded in this file. Q3, Q13 and Q14 are still open.

| ID | Question | Answer (owner, 2026-10-30) | Consequences |
|---|---|---|---|
| ~~Q1~~ | Brand name and domain? | Brand **Hochzeitslocation**, domain **hochzeitslocation.ch** (already registered). | `site.name` = "Hochzeitslocation"; canonical host `https://hochzeitslocation.ch`. #20 decides apex vs `www` and 301s the other. Title suffix "\| Hochzeitslocation". The mail sender uses an address on `hochzeitslocation.ch` (#14, #20). The wordmark stays text until the brand design. |
| ~~Q2~~ | Production hosting? | **Hostpoint** (Swiss shared webhosting) for PHP, **Neon** for PostgreSQL. | #20 starts from the Laravel attempt's `../hochzeitslocation/deploy/hostpoint.md` (control panel, PHP binary path, cron, GitHub Actions deploy, media rsync) and from songrequest's cyon + Neon notes (`../songrequest/docs/rpi-cycles.md` #7: libpq without SNI needs the endpoint ID in the password; never percent-encode `DATABASE_URL`). It verifies on Hostpoint: PHP 8.5 availability, `pdo_pgsql`, gd/Imagick with AVIF, cron, SSH + git, and the round-trip to Neon's nearest EU region. The full-page cache (#19) is what keeps Neon's cold start and latency off the visitor's path. |
| Q3 | Google Maps Embed API key (free, HTTP-referrer restricted). | **Open:** the owner provides it later. | Default stays: map facade with a keyless "Auf Google Maps öffnen" link. Setting `GOOGLE_MAPS_EMBED_KEY` later switches on the embed without a deploy of code. |
| ~~Q4~~ | Real venue content? | **Superseded by Q12 on 2026-10-30.** Originally: yes, copy from the Laravel project. | Was: #21 is scheduled (W5) and is the production content source. The owner takes responsibility for the rights to the venue texts, photos and contact data, which originate from eventlokale.ch. The content stays outside the repository (see **Important**). |
| ~~Q5~~ | Legal entity for Impressum and Datenschutz? | **Pick n Roll GmbH**, Sonneggstrasse 61, 8006 Zürich, UID-Nr.: CHE-134.034.546 MWST. Contact email **hallo@hochzeitslocation.ch**. | #18 uses this verbatim; no phone number. The mailbox does not exist yet: #20 creates it on Hostpoint and proves it receives mail before go-live. #18 drafts the Datenschutz text from the processors in LEG-1 for the owner to review. |
| ~~Q6~~ | Where do inquiries go in production? | **Directly to the venue by email.** Later they also go to our own CMS/CRM, built later. | IQ-2 sends to the venue's inquiry address in production. `INQUIRY_RECIPIENT_OVERRIDE` is for dev and staging only (Mailpit). Every inquiry is stored in `inquiries` with its full payload and mail status, so the future CRM can read the history. |
| ~~Q7~~ | Order of the paid tiers? | **As observed on eventlokale.** | Beliebte Location → Empfehlung → Top Premium → Premium → no badge (S6). |
| ~~Q8~~ | Are the CMS and the CRM live at launch? | **The CMS before launch, the CRM after.** | #22–#26 come before #20, which deploys them. From then on the database is the content master (S8). #27–#30 form W9–W10 after #20 and deploy themselves through #20's workflow. The CRM backfills the inquiries stored since launch (IQ-1). |
| ~~Q9~~ | Who logs in to the back office? | **Only our staff.** | No venue self-service (it is a proposal). Accounts are created by a console command, there are two roles (`admin`, `editor`), and 2FA is required (S12). |
| ~~Q10~~ | Which HubSpot concepts go into the CRM? | **Companies, contacts, timeline with notes, calls and tasks; deals pipeline; inquiries → contacts; sending e-mails.** | #27 (base), #28 (deals), #29 (inquiries), #30 (e-mail). Custom properties, inbox sync and reporting are proposals. |
| ~~Q11~~ | Visual design of the back office? | **A design reference made with Claude's design tool in the planning session; approved by the owner on 2026-10-30.** | Lives in `docs/reference/admin/` (S12). #22 and #23 build the tokens, the shell and the components from it, and every back-office cycle compares against it. |
| ~~Q12~~ | Can the owner export the Wix form submissions? They should hold the address, contact person and Instagram that the CSV lacks (S14). | **No (2026-10-30). `Locations+Startseite.csv` is the only production source, and venues without an address are accepted.** There is no form export. The external SSD is lost, and the eventlokale crawl cache and the Laravel project are out for everything. | Address, town, canton and coordinates become optional (#21). See the source change at the top of #21. |
| Q13 | Do the 4 Liechtenstein venues go on the site? | **Open.** | Default: no. `cantons` and `towns` are Swiss (#3), and the canton map and landing pages assume 26 cantons. #32 skips them and lists them in its report. A yes needs a small cycle of its own (proposal). |
| Q14 | What happens to deine-hochzeitslocation.ch at launch, and does any venue pay there (its home page mentions a "Premium-Eintrag")? | **Open.** | Default: the old site keeps running, no redirects are built, and a venue only in the CSV starts as `basic`. If the site is retired, #20 adds 301s from its `/hochzeitslocations/<slug>` and region URLs to the new pages, and `/listing` needs a replacement (proposal). A list of paying venues sets `tier` in #32. |

## Settled decisions

These are settled now so that parallel cycles do not each decide them differently. A cycle that finds strong evidence against one records it in its research and continues on the settled path. A settled decision changes only by an edit to this file.

### S1. Stack

- **Runtime:** Symfony 7.4 LTS on PHP 8.5, PostgreSQL 18, Doctrine ORM 3 / DBAL 4, Twig, Symfony Mailer, AssetMapper, nginx + php-fpm in Docker Compose (`php`, `nginx`, `database`, `mailpit`).
- **Same versions as `../songrequest`,** so its `CLAUDE.md`, `.claude/rules/*`, `Makefile`, `compose.yaml`, CI workflow and phpat tests can be copied and trimmed rather than invented.
- **No Node at runtime and no JS framework or library on the public site:** no Stimulus, Turbo, Swiper or jQuery. Build-time tools in a container are fine, e.g. the dart-sass standalone binary through `symfonycasts/sass-bundle`, or Lighthouse CLI for checks.
- **The one exception is the back office** (`/admin`, S12): Vue 3 islands, compiled at build time by Vite in a Node tools container. Node never runs on the server, and no public page loads Vue.
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
| `Admin` | back-office shell: staff users, login + 2FA, layout, dashboard, extension points, audit log, Vue island loader (S12) | — |
| `Cms` | back-office screens and read models for venues, rooms, photos, documents, landing-page texts, content blocks | Admin, Venues, Geo, Media |
| `Crm` | companies, contacts, timeline, tasks, deals, inquiry inbox, e-mail (S13) | Admin, Venues, Geo, Inquiries |

- No public module depends on `Admin`, `Cms` or `Crm`. `Cms` and `Crm` do not depend on each other: a CRM panel on a CMS page goes through an `Admin` extension point (S12).
- **Layers:** `Controller`, `Command`, `CommandHandler`, `Entity`, `Repository`, `ReadModel` (DBAL queries returning `final readonly` view objects), `ValueObject`, `Exception`, `Form`, `Console`, `Twig`, `Mailer`, `EventListener`, and from #22 `Extension` (a module's implementations of the `Admin` extension points).
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

### S8. Content files, then the CMS

- **Until the CMS (#24/#25):** real content lives in files, one YAML per venue plus a photo folder, kept outside `public/` (default `content/`, configurable via `CONTENT_ROOT`). `app:venues:import` (#9) imports it idempotently.
- **Dev and test** use Foundry: a `DemoStory` with about 60 fictional venues across all 26 cantons and all tiers, with generated placeholder photos (#4/#5).
- **Production content** comes from the Wix CSV (Q12): #21 turns it into content folders in `CONTENT_ROOT`, and #9 imports them like any other content. #32 then overlays those folders with the S14 facts and photos, so #9 stays the only write path.
- **From the CMS on, the database is the master.** Venues are created and edited in the CMS. The file import stays as a bulk path for onboarding (new keys) and for re-imports. It never silently overwrites a CMS edit: a venue with `cms_edited_at` later than its last import is skipped and reported, unless `--overwrite-cms-edits` is given (#24). Database and `MEDIA_ROOT` backups are therefore the content backups (#20).
- **One write path:** the import (#9) and the CMS (#24/#25) dispatch the same `Venues` commands. Slug history, room summaries, the photo pipeline (S9) and the cache purge live once, in Venues handlers.
- No eventlokale content goes into the repo; see **Important**.

### S9. Images

- Originals are kept outside `public/` (`MEDIA_ROOT`).
- Derivatives are generated **at import time, never on request.** They are written as `public/media/<venue-number>/<photo-id>-<width>.<avif|webp|jpg>` at widths 360/640/960/1280/1920. Each photo row stores width, height and dominant colour, which becomes the placeholder background.
- Pages use `<picture>` with AVIF, then WebP, then JPEG, an explicit `width`/`height`, `loading="lazy"` everywhere except the LCP image, and `fetchpriority="high"` on the LCP image.

### S10. Caching

- Public GET pages start no session and set no cookies; an application test asserts this.
- They send `Cache-Control: public, s-maxage=600, stale-while-revalidate=86400` (#19 tunes the values).
- #19 picks the reverse proxy: Symfony `HttpCache`, which works on shared hosting, or Cloudflare. #9's import purges it, and so does every CMS write that changes a public page (#24–#26).
- `/admin` is the only path with a session or cookies. It sends `Cache-Control: private, no-store` and `X-Robots-Tag: noindex, nofollow`, and the reverse proxy never stores it.

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
- **Every back-office UI cycle instead:**
  - the comparison with `docs/reference/admin/` at 1440 px, and a check that every screen it touched is usable at 390 px;
  - a keyboard-only walkthrough, and `make lighthouse URL=…` with an Accessibility score ≥ 95 on one screen it touched (logged in; #22 makes Lighthouse able to log in);
  - `make admin-lint` and `make admin-test`;
  - the ADM-8 JS budget.

### S12. Back office (`/admin`)

- **Users:** staff only (Q9). There is no sign-up. `app:admin:create-user <email> --role=admin|editor` creates an account, and `app:admin:reset-2fa <email>` resets 2FA. `admin` may do everything, including users; `editor` may use the CMS and the CRM but not users or deletions of companies and contacts. A role check is `#[IsGranted]` on the controller, never in Twig alone.
- **Security:** a firewall `^/admin` only, so public pages stay without a session (S10). It has form login, `login_throttling` (5 attempts per 15 min), TOTP 2FA required for every user (`scheb/2fa-bundle` + `scheb/2fa-totp`; research may pick an alternative), a session cookie with `SameSite=Strict`, `Secure` and `HttpOnly`, a 2-hour idle timeout, CSRF on every form and on every JSON write (a header token), and the S10 headers. The TOTP secret is encrypted at rest with a key from the environment.
- **Routes:** `/admin/…`, unlocalized (`LocalizedRoutes::UNLOCALIZED`), German UI only. There is one catalogue domain per module: `admin` (shell), `cms`, `crm`. Content forms show one tab per enabled locale (only `de` now), so a later language adds a tab without rework (S4).
- **Rendering:** server pages first, with Vue islands only where interaction pays off. A page is Twig plus Symfony Forms with POST → redirect → GET, like the rest of the app. A **Vue 3 island** is mounted on `<div data-island="name" data-props='{…}'>` by one loader entry, which lazy-loads the island's chunk. There is no SPA, no client router and no global store. Islands talk to JSON endpoints under `/admin/api/…`, which are ordinary controllers that read through read models and write through commands. The planned islands are the photo manager (#25), the room editor (#25), the global search / command palette (#23), the timeline composer (#27), the pipeline board (#28) and the e-mail composer (#30). Everything else is a server page, and a new island needs a reason in its research.
- **Toolchain:** `admin-ui/` at the repository root is a Vite project: plain JavaScript with `<script setup>` single-file components, and no TypeScript (GEN-2's spirit). Vitest + `@vue/test-utils`, ESLint. A `node` tools service in Compose (`profiles: [tools]`, like `lighthouse`) runs `make admin-install`, `make admin-build`, `make admin-watch`, `make admin-test` and `make admin-lint`. The build writes `public/admin-build/` (git-ignored) with Vite's `manifest.json`, and a Twig function `admin_island_assets()` reads the manifest. `make test` builds it first, as it does Sass, and `make lint` includes `admin-lint`. CI runs both. Production gets the built files from the deploy workflow, never from Node on the server (#20).
- **Styles:** one Sass root `assets/styles/admin.scss` (compiled by the existing sass-bundle) with its own tokens (`--a-*`) from the design reference, the shared Sofia Sans files and the Lucide sprite. Twig pages and Vue components use the same classes; SFCs have no `<style>` blocks, so there is one CSS system. CSP-safe: no inline scripts or `style` attributes in Twig, and Vue's runtime-only build (templates are precompiled, so there is no `unsafe-eval`).
- **Reads and writes:** as S2. Admin list and edit screens read through `Cms\ReadModel` / `Crm\ReadModel` (DBAL view objects). An edit form is filled from a read model into a form data class, and submitting it dispatches a command. Venue commands live in `Venues` (S8, one write path). Each edit form carries the `updated_at` it was loaded with, and the handler rejects a stale save with a `StaleEdit` domain exception ("Diese Location wurde inzwischen geändert"). This is optimistic locking without a version column.
- **Audit log:** a `command.bus` middleware (in `Admin`) writes `admin_audit_log` (actor, command, subject type and id, changed fields, time) for every command that implements `Shared\Messenger\AuditableCommand`. Public modules implement that Shared interface and never see `Admin`. The CRM timeline (#27) shows the audit entries of a company's venues.
- **Extension points** (the "open for extensions" part; interfaces in `Admin\Extension`, implemented in a module's `Extension` layer, autoconfigured as tagged services, ordered by a priority and filtered by role). A new back-office feature plugs in here, without editing the shell:
  - `NavigationItem`: an entry in the sidebar (section, label, icon, route, badge count);
  - `DashboardWidget`: a card on `/admin` (title, Twig template, size);
  - `RecordTab`: a tab on a record page (`venue`, `company`, `contact`, `deal`), e.g. #25's Fotos/Räume/Dokumente on the venue;
  - `RecordPanel`: a side panel on a record page, e.g. #27's "CRM" panel on the CMS venue page;
  - `SearchProvider`: results for the global search (venues, companies, contacts, deals);
  - `TimelineActivityType` (from #27): a kind of timeline entry with its renderer.
- **Design reference:** `docs/reference/admin/` holds the design from the planning session (Q11): the canvas source (`*.dc.html`, `canvas.json`), a `README.md` with the screen list, the `--a-*` token table and the link to the live, clickable design (https://claude.ai/artifact/LDRuCKBiQoQkUXNAG7vEtY). At its start, #22 adds a 1440 px screenshot per screen. Its look: calm, light work surfaces (a dark theme is a proposal) with the brand pink as the one accent; Sofia Sans; a HubSpot-like structure of a left sidebar, list views with filters in the query string, and record pages with properties on the left, the timeline or content in the middle and associations on the right. #22 and #23 turn it into tokens and components. Deviations are recorded in their Outcomes.
- **Uploads:** one file per request, so a slow host never times out on a batch. The photo manager uploads sequentially with progress. Photos are processed synchronously with #5's generator at upload time (S9: at import time, never on request). The limits are 25 MB per photo and 15 MB per PDF, and #20 sets PHP's `upload_max_filesize`/`post_max_size` to match.
- **Not in the back office:** venue self-service, public accounts, a WYSIWYG page builder, custom fields, a separate admin domain (all proposals).

### S13. CRM model (HubSpot, minimal)

- **Objects:** **companies** (the venue operators we sell to; a company has 0..n venues through `crm_company_venues`), **contacts** (people; 0..1 company; a `kind` of `venue_contact`, `couple` or `other`; e-mail unique when present, case-insensitively), **deals** (#28) and **tasks**. There are no leads, tickets, products or quotes.
- **Lifecycle:** a company has a `stage` of `prospect`, `customer` or `former_customer`, derived from its deals once #28 exists and set by hand before that. Contacts have no lifecycle.
- **Timeline:** one `crm_activities` table (type, `occurred_at`, actor, a short title, a body, and a JSONB `payload` per type), linked to objects through one join table per object (`crm_activity_companies`, `crm_activity_contacts`, and later `crm_activity_deals`). Types are code-keyed rows in `crm_activity_types` (`note`, `call`, `meeting`, `email_logged` in #27; `inquiry` in #29; `email_sent` in #30; `deal_stage_changed` in #28). A later cycle adds a type by inserting a row in its own migration, never by altering a CHECK. System events (venue published, tier changed) come from `admin_audit_log` at read time and are not copied.
- **Tasks:** title, due date, assignee (an admin user), done flag, linked to one object. "Heute fällig" and "Überfällig" sit on the dashboard.
- **Owner:** companies, contacts and deals have an optional owner (an admin user). With one or two staff it is mostly informative.
- **Lists:** every object list has search, filters and sorting in the query string, so a saved view is a bookmark. CSV export goes to `admin` users only.
- **Vocabularies** (contact kind, company stage, deal stages, activity types, lost reasons) are code-keyed rows or enums with labels in the `crm` catalogue (S4). Pipeline stages are rows, so the owner's later wish for another stage is a migration, not a code change.
- **Money:** CHF only, stored as integer Rappen (`amount_rappen BIGINT`), shown with `swiss_number`. No VAT logic and no invoicing.
- **Personal data (nDSG):** a contact can be exported (JSON) and erased by an `admin` user. Erasure deletes the contact and anonymises their timeline entries. Couples from inquiries follow the inquiry retention (LEG-2, #29). The Datenschutz page names the CRM as an internal processing purpose (#29 adds the paragraph to #18's text).

### S14. Owner data (deine-hochzeitslocation.ch)

**Source change (owner, 2026-10-30).** Q12 supersedes every part of this section that relies on the crawl, on #21's 758 venues or on an address: there is no crawl match, no merge with crawl data, and no "crawl wins" field. The CSV is the only source, so addresses, coordinates, rooms, ratings, reviews, FAQ, documents and distances stay empty unless staff enter them in the CMS.

- **Source.** The owner's current Wix site `deine-hochzeitslocation.ch` collects venue data from venue operators through the form "Location eintragen" (`/listing`). `Locations+Startseite.csv` is the export of its collection: 247 venues, snapshot of 2026-10-30. Unlike the crawl behind #21, the venues gave us this data themselves, so it wins wherever it says something. The file holds contact data and stays outside the repository (`CONTENT_ROOT/owner/`); tests use a small fictional fixture in the same shape.
- **Measured on the snapshot** (planning session, 2026-10-30; #32's research re-measures):

  | Finding | Number | Consequence |
  |---|---|---|
  | Always filled | `Name` (unique), `Typ`, `Region`, `Kapazität`, `Preisniveau`, `Email` (247); `Telefon` 245, `Website` 246 | Enough for a card once the address is known |
  | Address | **No column.** `Region` is a canton-like label, and only 2 texts mention a street | Optional since #21: a venue gets its canton from `Region`, staff add an address in the CMS (Q12) |
  | Photos | 2,143 unique photos on the 243 Swiss rows (`Anzeigebild` plus the `IMAGE` nodes inside `Vorstellungstext`); 4–13 per venue, median 10; 1,824 with alt text; median width 1,280 px, 451 below 1,280, minimum 370 | Far better rights and fit than the crawl's photos, smaller files. `https://static.wixstatic.com/media/<id>` returns the original (2 checked) |
  | Text | `Vorstellungstext` is Wix rich text as JSON (all 247 parse), 473–5,604 characters; labelled sections inside it; 11 have no "Beschrieb" heading, 15 have a description under 200 characters | Parse the sections; the rest stays description. `summary` does not exist and is derived |
  | Outside Switzerland | 4 Liechtenstein venues (`+423`) | Q13 |
  | No fixed place | 2 `Schweizweit`: a tent rental and a mobile theatre | Imported without an address: `Region` text in `translations.de.region`, no canton (#21). Staff set building `mobile_venue` in the CMS. |
  | Odd values | `Region` holds a town once (`Rümlang`) and non-cantons (`Romandie`, `Basel`, `Appenzell`); 1 phone is `365`; phones start with `'`; 3 phones and 5 emails repeat across venues of one operator | `Region` only cross-checks the canton from the town. Bad contact values are dropped and reported |
  | `Kapazität-Filter` | Derived bucket, wrong for 3 venues | Ignored |

- **Two form generations.** About 190 older entries (2025) have free-text sections: `Einrichtung` (789 distinct tokens), `Service`, `Essen / Trinken`, `Sperrstunde`. About 55 newer ones (`/listing` as it is today) have fixed checkbox values: `Infrastruktur`, `Essen`, `Party möglich?`, `Zeremonie`, `Zusammenarbeit Dienstleister?`. The newer ones map 1:1; the older ones need a token table, and what it does not cover is reported, never guessed. Only the older entries have `Kindermenu`, `Videograf` and `Weddingplanner`, so the vocabulary is the union of both.
- **Fields of the form that are not in the CSV:** `Firmenname`, `Strasse, PLZ + Ort`, `Anrede`, `Vorname`, `Nachname`, `Instagram`. The Wix form submissions may hold them (Q12).
- **Which source wins:** void since Q12 (no crawl, so no merge and no terms union). #21 owns the base fields, #32 the facts and photos (`docs/content-format.md` §Generated folders), and staff edits win from #24 on.
- **New data** (#31 adds it; #4 cannot hold it; the figures are #32's, on the 243 Swiss rows by section label):

  | Owner data | Filled in CSV | Target |
  |---|---|---|
  | `Preisniveau` (Moderat 41, Mittel 181, Exklusiv 21) | 247 | `venues.price_level`: enum `PriceLevel` (`moderate`, `medium`, `exclusive`) |
  | `Ambiente` (176 distinct free texts) | 247 | `venue_translations.ambience` ≤ 60, verbatim; plus a `setting` term only where the text names one (See → `lake`, Alpen/Berg → `mountains`, Urban → `city`, Im Grünen/ländlich → `countryside`) |
  | `Kapazität für gesetztes Essen` / `Stehempfang / Apéro` | 193 / 197 as numbers, 50 with several numbers | `venues.capacity_seated`, `venues.capacity_standing`; the original wording in `venue_translations.capacity_note` ≤ 250 |
  | `Sperrstunde` (older), `Party möglich?` (newer) | 191 + 57, 167 distinct | `venues.party` enum `PartyAllowed` (`yes`, `limited`, `no`), `venues.has_curfew` (NULL = unknown), `venues.curfew_at` TIME, and the original wording in `venue_translations.party_note` ≤ 250. "Sperrstunde: Nein" (44) means no curfew; "Ja" alone (0) means a curfew at an unknown time |
  | `Öffnungszeiten` (110 distinct) | 190 | `venue_translations.opening_hours` ≤ 250, verbatim |
  | `Zeremonie` (4 checkboxes); older `offizielles Standesamt-Lokal`, `Kapelle` | 51 + about 50 | new term group `ceremony`: `civil_ceremony`, `free_ceremony`, `church_nearby`, `rain_alternative`, `registry_office`, `chapel` |
  | `Infrastruktur`, `Einrichtung` | 55, 190 | existing codes where they fit (`surroundings.terrace`, `surroundings.garden`, `technology.projector_screen`, `technology.sound_system`, `infrastructure.stage`), else new group `amenity`: `overnight`, `panorama`, `lake_view`, `dance_floor`, `kids_room`, `outdoor_dining`, `parking`, `parking_area`, `own_kitchen`, `catering_available`, `external_caterers`, `corkage`, `wheelchair_access`, `public_transport`, `tent`. `external_caterers` also sets `own_catering = allowed`. `Zapfengeld` is a keyword without an amount. A stated parking count goes to `parking_text` |
  | `Essen`, `Essen / Trinken` | 56, 185 | new group `dining`: `vegetarian`, `vegan`, `gluten_free`, `organic`, `allergy_friendly`, `diabetic`, `halal`, `kosher`, `kids_menu`, `wedding_cake`, `buffet`, `grill`, `anything_possible` |
  | `Zusammenarbeit Dienstleister?`, `Service` | 40, 180 | new group `vendor`: `flowers_decor`, `photography`, `videography`, `officiant`, `dj`, `musicians`, `furniture`, `wedding_planner` |
  | `Nutzung` | 247 | new group `usage` (a venue may have several): `exclusive`, `multiple_weddings`, `wedding_and_regular` |
  | `Sprachen die vom Personal gesprochen werden` | 224 | new group `language`: `de`, `en`, `fr`, `it`, `es`, `pt`, `nl`, and whatever #32's token count shows with ≥ 3 venues |
  | `Instagram` (form only) | 0 | `venues.instagram` |

- **`Typ` → main building:** Hotel, Hotel Restaurant → `hotel` (+ `restaurant`); Restaurant, Gasthof → `restaurant`; Burg & Schloss → `castle`; Saal, Eventhalle → `event_hall`; Gutshof & Weingut → `brewery_winery`; Scheune → `farm_barn`; Waldhaus → `forest_hut`; Kultur-Gästehaus → `cultural_centre`; Originelle Location (85 venues) → `experience_creative`.
- **Nothing is inferred.** A fact is set only when the owner stated it in its own field. No keyword search in the description: a false "Zivile Trauung möglich" is a booking-ruining error nobody would catch in review. Unknown stays NULL or absent, and the page shows nothing for it. Spelling is kept verbatim (no rewriting of texts).
- **Coverage.** After #32, 243 of 243 venues have a price level and an ambience; the other facts only where the venue stated them, so a ceremony filter (#33) shows only those. This is accepted: an honest short list beats invented facts, and the CMS (#24) or a later questionnaire (proposal) fills the rest. Empty facts are hidden on every page.

## Requirements

IDs are referenced by the overview and the cycles.

**General**
- **GEN-1** German UI only for now, with every string in translation catalogues (S4).
- **GEN-2** Server-rendered Symfony + Twig. No TypeScript or JS framework or library; every public page is usable without JS (S3). The back office is the one exception: Vue islands, no TypeScript (S12).
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
- **LP-7** The card shows the owner's Preisniveau and Ambiente as two small tags when they exist, as the Wix card does. Cards without them look exactly like LP-2.

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
- **FI-7 Hochzeit** (our addition, not on eventlokale): a section in the Weitere-Filter overlay, directly after "Name eingeben", with Preisniveau, Trauung, Keine Sperrstunde, Nutzung exklusiv, and the useful `amenity` codes (Übernachtung, Tanzfläche, Seeblick, Panoramablick, Essen im Freien, Externe Caterer erlaubt, rollstuhlgängig). It filters only on stated facts (S14).

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
- **DP-17 Hochzeit:** a section after the facts (DP-7) with the owner facts of S14: Preisniveau, Ambiente, Sitzplätze and Stehplätze, Trauung, Party und Sperrstunde, Essen, Nutzung, Dienstleister, Sprachen, Öffnungszeiten. Each line appears only when it is known, and the section disappears when nothing is. An Instagram link joins Kontakt (DP-11).

**Inquiries (IQ)**
- **IQ-1** Inquiries are stored in `inquiries` with venue, full payload, created_at and a status for the mail outcome. The schema is designed so the CRM (#29) can take them over unchanged (Q6): it reads `inquiries` and never alters the table.
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
- **DATA-3** Base content export from the owner's Wix CSV (Q12), the production content source.
- **DATA-4** The venue model holds the owner facts of S14, and the content format, the demo data and the detail page carry them.
- **DATA-5** Owner data import (S14): the Wix export is merged into the content folders, owner data wins per S14's table, and nothing is inferred.

**Operations (OP)**
- **OP-1** Docker dev stack, Makefile, CI (lint + test) on GitHub Actions.
- **OP-2** Production deploy with HTTPS, a manual deploy workflow gated on green CI, and a health check.
- **OP-3** Error monitoring (Sentry, EU region, like songrequest) and database backups.
- **OP-4** The back office in production: the deploy ships the built `admin-ui` files, PHP's upload limits match S12, `MEDIA_ROOT` is persistent and backed up (S8: the database and media are the master), and the first `admin` user with 2FA exists.

**Back office (ADM)**, per S12
- **ADM-1** Staff login at `/admin/login` with e-mail and password, then TOTP. Logout. Login throttling. No sign-up and no password-reset mail (an `admin` user resets passwords from the users screen or the console).
- **ADM-2** Roles `admin` and `editor` (S12). A users screen (`admin` only) lists, creates, deactivates users and resets their 2FA.
- **ADM-3** An audit log of every back-office write (S12), with a screen (`admin` only) filtered by actor, subject and date.
- **ADM-4** The shell per `docs/reference/admin/`: sidebar with sections (Übersicht, CMS, CRM, Einstellungen), a top bar with global search (⌘K / Ctrl+K), user menu, flash toasts, breadcrumbs; a dashboard of widgets. German UI from the catalogues.
- **ADM-5** Extension points (S12) with a test that a fake module's navigation item, dashboard widget, record tab and record panel appear.
- **ADM-6** The back office never changes public behaviour: public responses still start no session and set no cookie, `/admin` is `private, no-store` and `noindex`, and `robots.txt` disallows `/admin` (#16).
- **ADM-7** WCAG 2.2 AA in the back office too: keyboard-operable tables, forms, drag and drop with a keyboard alternative (move up/down buttons), visible focus, labelled controls, errors linked to their fields.
- **ADM-8** Budgets: shared admin JS (loader + Vue runtime) ≤ 60 KB gzip, each island chunk ≤ 30 KB gzip, admin CSS ≤ 30 KB gzip. A server page loads no island it does not use. Uncached admin pages answer in p95 ≤ 300 ms locally with the real data.

**CMS**
- **CMS-1** Venue list (`/admin/locations`): search by name or number, filters for status, tier, canton and "unvollständig" (no photo, no inquiry e-mail, summary missing, no main building), sorting, 50 per page, the counts per status. Every row links to the editor and to the public page.
- **CMS-2** Venue editor in sections that match #4's fields. **Stammdaten:** name, tier, capacity. **Texte:** summary (with a ≤ 200 counter), description, meta description, parking, one tab per locale. **Adresse & Lage:** street, ZIP/town through #3's lookup, coordinates with a "Ortsmitte übernehmen" button, distances with names. **Merkmale:** chips per term group, with main building and main setting. **Hochzeit:** the S14 facts (price level, ambience, seated and standing capacity, party, curfew, opening hours; the groups `ceremony`, `amenity`, `dining`, `vendor`, `usage`, `language` as chips). **Kontakt:** phone, inquiry e-mail, website, contact person, Instagram. **Häufige Fragen.** **Bewertung:** score, count, highlights. Every rule of #4's CHECKs is a form error before it is a DB error (validation strategy).
- **CMS-3** Create a venue (it starts unpublished), publish and unpublish (publishing needs a summary, a main building and ≥ 1 photo), change the tier, delete only when unpublished and only for `admin` (with confirmation; media removed via `MediaPublisher::removeVenue()`).
- **CMS-4** A name change proposes a new slug; saving keeps the old one in `venue_slug_history` (301, S5). The slug can be edited by hand and is checked for uniqueness.
- **CMS-5** Rooms: add, edit (name and description per locale, capacity, area, height, floor material, the four seating capacities), reorder and delete. The venue's room facts are recomputed on every save (#4's `summariseRooms()`).
- **CMS-6** Photos: upload (JPEG/PNG/WebP/HEIC if gd reads it; ≤ 25 MB) with variants generated at upload (S9), reorder by drag and drop and by keyboard, alt text per locale, assign to a room or the venue, delete, see the first photo marked as the card/LCP image. A replaced original gets a new photo id (#5).
- **CMS-7** Documents: upload a PDF (≤ 15 MB), title per locale, reorder, delete.
- **CMS-8** Reviews and rating: edit the imported reviews (first name, role, month, text), delete one, add one by hand; edit score, count and the three highlights.
- **CMS-9** Preview: any venue, published or not, can be viewed as its public detail page at `/admin/locations/{number}/vorschau` (inside the admin firewall, `noindex`, never cached, with a "Vorschau" banner). Published venues also get an "Auf der Website ansehen" link.
- **CMS-10** Every CMS write that changes a public page purges the affected URLs through #9's `HttpCachePurger` (the detail page, the listing, the venue's canton page, the sitemap).
- **CMS-11** DB is master (S8): the import guard with `cms_edited_at` and `--overwrite-cms-edits`, and a "Zuletzt bearbeitet von … am …" line from the audit log.
- **CMS-12** Pages and text blocks: the intro text, SEO title and meta description of each landing page (#17: canton, town and building type), and a registry of named text blocks (Markdown, safe-rendered) that modules declare for their slots, e.g. the listing intro or the footer note. Legal pages stay in Twig under version control (#18) and are not editable here.

**CRM**, per S13
- **CRM-1** Companies: list, create, edit (name, legal name, website, phone, e-mail, address, stage, owner, notes), link and unlink venues, delete (`admin`). `app:crm:bootstrap` and a button create one company per venue that has none, with the venue's contact person as a `venue_contact` contact. It is idempotent.
- **CRM-2** Contacts: list, create, edit (first name, last name, e-mail, phone, role/position, kind, company, owner), delete and export (`admin`, S13).
- **CRM-3** Record page per object as in the design reference: properties on the left (inline editable, saved per field), timeline in the middle, associations on the right (company ↔ contacts ↔ venues ↔ deals), and record tabs and panels from the extension points.
- **CRM-4** Timeline: log a note, call, meeting or e-mail (manually) with date and text; edit or delete your own entries; filter by type. System events from the audit log appear read-only.
- **CRM-5** Tasks: create from any record or the task list, with a due date, an assignee and done/undone; a task list (`/admin/crm/aufgaben`) with "Meine", "Heute", "Überfällig"; dashboard widgets.
- **CRM-6** Lists: search, filters, sorting in the query string, 50 per page, CSV export (`admin`), global search results (S12 `SearchProvider`).
- **CRM-7** Deals: name, company, contacts, venue, tier, amount (CHF), contract start and end, expected close date, stage, owner, lost reason. A pipeline board (Kanban) per stage with a sum per column; drag and drop plus a keyboard "move to stage" menu; a list view as an alternative. Stages: Neu → Kontaktiert → Angebot → Verhandlung → Gewonnen / Verloren.
- **CRM-8** Won and renewals: a won deal offers "Tier auf Location anwenden", which dispatches Venues' tier command (audited, cache purged). A deal whose contract ends within 60 days shows in the "Verlängerungen" widget, and `app:crm:renewal-tasks` (daily cron) creates one renewal task per deal, idempotently. "Verlängern" copies the deal into the next period.
- **CRM-9** Inquiries: every inquiry from #14 creates or updates a `couple` contact (by e-mail) and adds an `inquiry` entry to that contact's timeline and to the timeline of the venue's company. An inbox `/admin/crm/anfragen` lists them with a status (neu, gesehen, erledigt) and the mail outcome. `app:crm:backfill-inquiries` imports the history. Ingestion runs after the inquiry is stored and never makes the guest wait or fail.
- **CRM-10** E-mail: compose from a contact or company record (to the contact's e-mail, optional CC), from a template with merge fields (`{contact.first_name}`, `{company.name}`, `{venue.name}`; single braces, so a template is never Twig), sent through Symfony Mailer from the `hochzeitslocation.ch` sender with the staff member's name and `Reply-To: hallo@hochzeitslocation.ch`, logged as an `email_sent` activity with its delivery status. Replies arrive in the mailbox and are not tracked (inbox sync is a proposal). `INQUIRY_RECIPIENT_OVERRIDE`'s rule holds here too: outside production every CRM mail goes to Mailpit (`CRM_MAIL_OVERRIDE`).
- **CRM-11** Personal data: export and erasure per S13; couples whose last activity is older than the inquiry retention (LEG-2, 12 months) and who have no deal are deleted by `app:crm:purge` (cron), together with their inquiry links.

## Reference material

- **Original pages:** listing https://www.eventlokale.ch/locations/hochzeit/seedId=33514164, detail https://www.eventlokale.ch/site/_eventlokale/1/Schweiz/1093/stufenbau_eventlocation.html.
- **Screenshots** in `docs/reference/`:
  - `listing-page-top.jpg`, `listing-card.png`;
  - `filter-region.jpg`, `filter-region-selected.png`, `filter-guests.jpg`, `filter-more.jpg`;
  - `detail-page-top.jpg`.
- **Site audit:** https://claude.ai/artifact/PzKBEaWRr9m6ZKVAUL4fto, 2026-09-08. Mostly out of scope; useful for its SEO, performance and security findings, i.e. what *not* to copy.
- **Owner data:** the current site https://www.deine-hochzeitslocation.ch, its form "Location eintragen" at `/listing`, and the CSV export (S14, outside the repository).
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

**Outcome (2026-10-30).** Done in commit `design-system`, rebased onto #2 and #3.

- **Sass pipeline:** `symfonycasts/sass-bundle` ^0.10 (`config/packages/symfonycasts_sass.yaml`, `style: compressed`) with the dart-sass 1.105.2 standalone binary baked into the PHP image (`docker/php/Dockerfile`, `/usr/local/bin/sass`; **rebuild the image** with `make build` or `make init` after pulling). Two roots: `assets/styles/app.scss` (every page) and `assets/styles/styleguide.scss` (styleguide only). Built CSS lands in `var/sass/<name>-<hash>.output.css` (e.g. `app-7d1043473d.output.css`, not the plan's `app.built.css`); AssetMapper serves it as `asset('styles/app.scss')`. **A cycle adds its styles** as `assets/styles/<module>/_<name>.scss` plus ONE `@use '<module>/<name>';` line appended to the end of `app.scss` (hotspot, append only; `@use`, never `@import`). `_*.scss` partials are excluded from the asset map. `make sass` builds, `make sass-watch` rebuilds on change; `sass` runs first in `make init`, `make test` and `make test-short`, so CI (which runs `make test`) builds Sass too. No importmap entries (`importmap.php` returns `[]`), no es-module-shims polyfill (`importmap_polyfill: false`), `assets/app.js` and `assets/styles/app.css` are gone (#0 carry-forward done).
- **Tokens** (`assets/styles/abstracts/_tokens.scss`, the only `:root`; partials use `var(--…)`, no hex): `--c-bg #121212`, `--c-ink #fff`, `--c-ink-inverse #121212`, `--c-surface #272727` (header), `--c-surface-2 #444` (card), `--c-surface-3 #535353` (filter bar, tier badge, round button), `--c-surface-footer #2c2c2c`, `--c-hero-circle #313131`, `--c-line-muted #5f5f5f`, `--c-muted #c5c5c5`, `--c-accent #ff5a75`, `--c-accent-hover #ec536c`, `--c-accent-strong #c4455a` (selected/hover canton), `--c-accent-soft #ffc1cb` (bubble hover), `--c-black #1b1b1b`, `--c-lake #6a8396`, `--c-map-land #ff5a75`. Sampled overlay tones: region `#6a9691`, guests `#756a96`, more `#9e9a93` with bar `#8e8b85`; circles `rgb(0 0 0 / .05)`.
- **Contrast deviations from the original** (S3, guarded by `tests/Unit/Shared/ContrastTokensTest.php`; a new colour pair gets a row there): (1) text on pink is `#121212` (6.22:1) instead of white (3.01:1); (2) region overlay `#577b77` instead of `#6a9691` (white 4.66:1); (3) more overlay `#76746e` instead of `#9e9a93` (4.67:1); (4) more-overlay bar `#6a6863` instead of `#8e8b85`; (5) inactive states use weight or underline, never reduced opacity. Guests `#756a96` is unchanged.
- **Type, spacing, radii, shadows:** mobile → md → lg/xl: hero 20/24 → 36/46 → 65/75 px, overlay title 25/35 → 40/50 → 65/75 (40/50 when height ≤ 800 px), card title 24 → 21 → 30 px (xl), count 16 → 24 → 30 px, large input 24 → 40 px (xl), body 16/24, small 14, tag 12. Spacing `--space-2xs…3xl` = 4, 8, 12, 16, 27, 40, 60, 85 px. Radii `--r-badge 3px`, `--r-button 3px`, `--r-tooltip 4px`, `--r-bar 6px`, `--r-pill 20px`, `--r-round 50%`. Shadows `--sh-map` (a `filter` value, `drop-shadow(8px 13px 0 rgb(0 0 0 / .15))`), `--sh-bubble`, `--sh-round`, `--sh-tooltip`, `--sh-dropdown`. Breakpoints (`abstracts/_breakpoints.scss`, `@use '../abstracts/breakpoints' as bp;` → `@include bp.up(md)` / `bp.down(md)`): sm 568, md 768, lg 1024, xl 1440.
- **Fonts:** Sofia Sans latin 400 and 600 woff2 (`assets/fonts/sofia-sans-latin-{400,600}-normal.woff2`, from `@fontsource/sofia-sans` 5.3.0, OFL in `assets/fonts/SofiaSans-OFL.txt`), `@font-face` in `base/_fonts.scss` (`unicode-range` from fontsource's `400.css`), 400 preloaded in `base.html.twig`. No 700.
- **Base layout** `templates/base.html.twig` (#1 owns it and `templates/layout/*`; fill blocks, don't restructure): `<html lang="{{ app.request.locale }}">`; blocks `title` (default `layout.site_name`), `meta`, `canonical`, `hreflang` (default `{{ include('shared/_hreflang.html.twig', ignore_missing = true) }}`, now #2's partial: `/` renders `de-CH` + `x-default`), `stylesheets` (after `app.scss`), `jsonld`, `body_class`, `hero`, `main` (inside `<main id="main" tabindex="-1">`), `scripts`. Skip link, toast live region `<div class="toast-region" role="status" aria-live="polite" data-toast-region>` (#13 fills it), favicon `assets/icons/favicon.svg`. Partials: `layout/_header.html.twig` (hamburger opening the `#main-menu` popover, centred wordmark, search and heart with a hidden `[data-merkliste-count]` badge; no user icon), `layout/_main_menu.html.twig` (the menu `<li>`s; later cycles append one `<li>` each), `layout/_footer.html.twig`.
- **Slots for later cycles:** #7 changes the search link's `path('home')` and #13 the heart's `path('home')` in `_header.html.twig` (both link to `home` until then); #17 creates `templates/seo/_footer_cantons.html.twig` and #18 `templates/site/legal/_footer_links.html.twig` (both `ignore_missing` includes in the footer); menu items go into `templates/layout/_main_menu.html.twig`.
- **Components** (`templates/components/`, each with a partial in `assets/styles/components/`; pass already-translated strings; full list also in `.claude/rules/frontend.md`). Atoms via `{% include '…' with {…} only %}`:
  - `_button.html.twig`: `label`, `variant` (primary|black|outline|round|round-lg|icon|close), `href` (renders `<a>`), `type`, `icon`, `label_hidden`, `attributes`;
  - `_chip.html.twig`: `label`, `href`, `remove_label`, `variant` (active|neutral); `_badge.html.twig` (tier badge): `label`; `_toast.html.twig`: `message`, `link_href`, `link_label`;
  - `_text_input.html.twig` (underlined large input): `id`, `name`, `label`, `type`, `value`, `placeholder`, `autocomplete`, `label_hidden`.
  - Shells via `{% embed '…' with {…} only %}`: `_overlay.html.twig` (`popover`, full screen, two decorative circles, round ×, sticky footer): `id`, `tone` (region|guests|more), `title`, `close_label`, blocks `overlay_body`, `overlay_footer`; `_dialog.html.twig` (`<dialog>`, opened by `commandfor` + `command="show-modal"`): `id`, `title`, `close_label`, blocks `dialog_body`, `dialog_footer`; `_carousel.html.twig` (scroll-snap): `id`, `label`, `previous_label`, `next_label`, `counter_format` (default `layout.carousel.counter`, `{current} / {total}`, renders `1 / 4`), block `slides` of `<li class="carousel__slide">`.
- **Icons:** `{{ icon('name', {label?, class?}) }}` from `App\Shared\Twig\IconExtension` (final, `#[AsTwigFunction]`); without `label` it is `aria-hidden`, with one `role="img"`. Sprite `assets/icons/sprite.svg`: 36 Lucide 1.57.0 symbols (ISC; licence and icon list in `assets/icons/LICENSES.md`), filled heart = `heart` + `class: 'icon--filled'`. Lage: `am_fluss`→`waves`, `am_see`→`sailboat`, `auf_dem_land`→`trees`, `in_den_bergen`→`mountain-snow`, `in_der_stadt`→`building-2`; highlights: `architektur`→`landmark`, `stil_atmosphare`→`sparkles`, `ausstattung`→`sofa`, `lage_aussicht`→`binoculars`, `erreichbarkeit`→`route`, `kulinarik`→`utensils`, `programm`→`music`, `freundlichkeit`→`smile`, `preis_leistung`→`coins` (Lucide, not hand-drawn). A new icon = a `<symbol>` in the sprite plus a line in `LICENSES.md`; `IconSpriteTest` checks names and forbids presentation attributes.
- **JS:** self-contained modules (no `import`/`export`, no globals) `assets/js/carousel.js` (arrows, counter, keyboard; 858 B gzip), `overlay.js` (focus to ×, Tab wraps, for `[data-overlay][popover]`), `dialog.js` (fallback opener, backdrop click closes). A page loads one with `<script type="module" src="{{ asset('js/x.js') }}"></script>` in `{% block scripts %}`. No `importmap()`, no inline `<script>`, no `style` attributes (CSP-ready for #16). Everything works without JS (popover/`commandfor`/scroll-snap).
- **Styleguide:** `/_styleguide` (route `_styleguide`, `config/routes/styleguide.yaml`, `when@dev` + `when@test` only, `TemplateController`, default `_locale: de`, `noindex`); template `templates/styleguide/index.html.twig` with 14 `[data-component]` sections, the last one the canton map slot `templates/styleguide/_canton_map.html.twig` (`{{ canton_map() }}` from #3, styled by `.sg-map` in `styleguide.scss`). It is unlocalized; #2's `PublicRoutesAreLocalizedTest` skips `_` routes. `?_profile=0` turns the dev profiler toolbar off for one request.
- **Catalogues:** `translations/messages+intl-icu.de.yaml` (layout strings under `layout.*`; #1 owns it, nobody else adds to it) and `translations/styleguide+intl-icu.de.yaml` (styleguide demo copy, domain `styleguide`). Both pass #2's `CatalogueParityTest` and `TranslationCompletenessTest`.
- **Tooling:** `make lighthouse URL=/path` runs Lighthouse 13.5.0 (mobile) in a throwaway Node container (`docker/lighthouse/`, compose service `lighthouse`, `profiles: [tools]`, `network_mode: service:nginx`), prints the four scores and writes `var/lighthouse/<timestamp>-<path>.report.{json,html}`. `make validate-html URL=/path` runs the Nu Html Checker (`ghcr.io/validator/validator:latest@sha256:8f2103…`, version 26.10.12, multi-arch); because vnu refuses `localhost` URLs, the php container first saves the page via nginx to `var/validate/page.html` and vnu checks that file. Both append `_profile=0`.
- **Rebase onto #2/#3:** removed #1's `base.html.twig` entry from `TemplatesHaveNoRawTextTest::BASELINE`; the styleguide prints its `<code>` token labels as Twig expressions so the raw-text scan passes without a baseline entry. Pages now render German copy; `debug:translation de --only-missing` is empty.
- **Rules and docs:** `.claude/rules/frontend.md` (tokens, partials, components, icons, JS, slots, per-UI-cycle checks) and a `### Shared` paragraph in `CLAUDE.md`.
- **Gates measured after the rebase** (lane A, Lighthouse 13.5.0 mobile): `/_styleguide` and `/` both Performance 100, Accessibility 100, Best Practices 100, SEO 50. `app.css` 18.0 KB raw / 5.0 KB gzip (budget 25 KB), `styleguide.css` 1.7 KB gzip; JS 2.1 KB gzip for all three modules (budget 15 KB per page), `carousel.js` 858 B (budget 2 KB); 2 font files. vnu on `/_styleguide` and `/`: "No errors found". `AssetBudgetTest` guards the sizes. `make test`: 187 tests, none skipped.
- **Deviations:** built CSS name `var/sass/<name>-<hash>.output.css` (`AssetBudgetTest` globs it); the header row is full width, not `.container` (matches the screenshot); `IconSpriteTest` allows `<rect width/height>` geometry inside symbols; vnu is pinned by digest because the newest dated tag (24.10.17) is amd64-only and predates `commandfor`; the menu items live in a fixed-path partial instead of a block (PD-1). On this host Docker's address pools are exhausted, so a worktree may need `networks.default.ipam.config: [{subnet: 172.16.8x.0/24}]` in its git-ignored `compose.override.yaml`.
- **Carried forward:**
  - Review F-m2: the hamburger popover dims the page with a `::backdrop` but doesn't keep focus inside; Tab reaches the wordmark under the backdrop. Give `#main-menu` the overlay focus wrap (`data-overlay` + load `overlay.js` from base) or drop the backdrop. Owner: #7 or #13, whichever touches the header first.
  - Reduced motion is verified in code only (`carousel.js` uses `behavior: 'auto'`, `_base.scss` forces `scroll-behavior: auto`), not emulated in a browser.
  - SEO 50 in dev is expected (`X-Robots-Tag: noindex` outside prod, styleguide `noindex`, no meta description yet); #16.
  - Header search and heart link to `home` until #7/#13.
  - The carousel counter has no `aria-live`; #8 may add `aria-live="polite"` for the gallery.

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

**Outcome (2026-10-30).** Done in commit `geo-reference-data`. No Composer changes, no routes.

- **Migration `Version20261030105455`** (full `down()`) adds three SQL functions and three tables:
  - `geo_fold(text)` (lower-case, `unaccent`, non-alphanumerics → one space), `geo_fold_de(text)` (ä/ö/ü → ae/oe/ue, then `geo_fold`) and `geo_search_key(text)` (`geo_fold_de || ' ' || geo_fold`), all `IMMUTABLE PARALLEL SAFE STRICT` with SQL-standard `RETURN` bodies. They exist because `unaccent()` is only STABLE and cannot be used in an index expression; the wrappers call it with an explicit dictionary, which makes the index possible. New SQL that compares place names must fold through them so it hits the index.
  - `cantons`: PK `code` VARCHAR(2), lower-case (`zh`), a code-keyed vocabulary (documented exception to `EntityId`); `bfs_number` (1–26, unique), `slug` (German ASCII, unique: `zuerich`, `st-gallen`), `svg_id` (`CHECK svg_id = 'ch-' || code`), `centroid_lat`/`centroid_lng` (inner points of the BFS 2026 polygons). The 26 rows are seeded by the migration itself.
  - `towns`: PK `id` UUID; `canton_code` FK → `cantons(code)` ON DELETE RESTRICT; `zip` (the lowest of the town), `name` (official Ortschaft), `slug` (AsciiSlugger de), `bfs_number`, `lat`, `lng`, `population` (per **municipality**). Constraints `uk_towns_canton_code_name`, `uk_towns_canton_code_slug`, CHECKs on zip/lat/lng/population. Indexes: GIN trigram `idx_towns_search_key` on `geo_search_key(name)` (the spec's "trigram on `unaccent(name)`"), GiST `idx_towns_earth` on `ll_to_earth(lat, lng)` (radius), btree `idx_towns_zip`, `idx_towns_canton_code`. The expression indexes are deliberately not mapped in Doctrine (`schema:validate` stays green).
  - `town_zips`: PK `(zip, town_id)`, FK `town_id` → `towns(id)` ON DELETE CASCADE; every ZIP of a town, so all of them stay searchable.
- **Entities / repository:** `App\Geo\Entity\Canton`, `App\Geo\Entity\Town` (ManyToOne `canton()`), `App\Geo\Entity\TownZip`, mapped by the new `orm.mappings.Geo` block in `config/packages/doctrine.yaml`; `App\Geo\Repository\TownRepository` (DBAL upserts for the import). **#4 references them by FK:** `town_id UUID → towns(id)` and/or `canton_code VARCHAR(2) → cantons(code)` (Venues → Geo is allowed by S2, so a ManyToOne to `Geo\Entity\Town` is fine).
- **`App\Geo\ValueObject\Canton`:** string enum, 26 cases in BFS order (`ZH = 'zh'` … `JU = 'ju'`), implements `TranslatableInterface` (`trans()` → `canton.<code>` in the `geo` domain). Slug, `svg_id` and centroid live only in the `cantons` table. Labels: `translations/geo+intl-icu.de.yaml` (`canton.zh: Zürich`, …). Also `PlaceType` (`Canton|Town`).
- **Import:** `bin/console app:geo:import-towns [path]` (`App\Geo\Console\ImportTownsCommand` → `App\Geo\Command\ImportTowns(csvPath)` on `command.bus` → `App\Geo\CommandHandler\ImportTownsHandler` → `TownRepository`). It reads `data/geo/towns.csv` (byte copy from the Laravel project, `-text` in `.gitattributes`, sources in `data/geo/SOURCE.md`) and collapses 4,057 rows to **one town per `(canton, name)` with the lowest ZIP** supplying every field, keeping all 4,057 ZIPs in `town_zips` → 3,959 towns. Idempotent (`ON CONFLICT … DO UPDATE … WHERE … IS DISTINCT FROM`; second run: `0 inserted, 0 updated, 3959 unchanged`), one transaction, **never deletes towns** (stale towns are only reported as `Name (CANTON)`), deletes only stale ZIP rows. **`make seed` now runs it first**, before `foundry:load-fixtures`; production must run it once after migrating (#20).
- **Read models** (no caller yet, so the services are removed at compile time until #6 injects them):
  - `App\Geo\ReadModel\PlaceLookup::suggest(string $q): list<PlaceSuggestion>`: trims and cuts input to 100 chars; returns `[]` if it has no letter or digit; 1–4 digits → ZIP prefix (exact first); text → cantons by slug prefix first, then towns by name prefix → word prefix → trigram, each by population; at most 8. `PlaceSuggestion`: `type` (`PlaceType`), `cantonCode`, `?townId`, `?name`, `?zip`; canton entries carry only the code (label via the enum).
  - `App\Geo\ReadModel\TownLocator::withinKm(string $townId, float $km): list<TownDistance>`: throws `InvalidArgumentException` unless 0 < km ≤ 200; returns `[]` for a non-UUID id; `earth_box` + `earth_distance`, nearest first, centre town included. `TownDistance`: `townId`, `name`, `cantonCode`, `zip`, `distanceKm`.
- **Canton map:** `assets/maps/cantons.svg`, 26,690 bytes, `viewBox="0 0 800 533"` only, `<g id="cantons">` with 26 `<path id="ch-xx">`, `<g id="lakes">` with 22 lakes on top, presentation attributes only on the groups. Source: BFS GEOSTAT *Generalisierte Gemeindegrenzen* / Historized boundaries K4, **state 1.1.2026** (Moutier already in JU), simplified with mapshaper + SVGO; recipe and Moutier check in `assets/maps/LICENSES.md`. Twig: `{{ canton_map() }}` (`App\Geo\Twig\CantonMapExtension`, `isSafe: html`, file read once) inlines it.
- **Licences:** canton map OPEN-BY (BFS DAM asset 36389714), credit "Quelle: Bundesamt für Statistik (BFS), GEOSTAT"; town data swisstopo free geodata + BFS OPEN-BY, credit "Quelle: swisstopo, Amtliches Ortschaftenverzeichnis; Bundesamt für Statistik (BFS)". Both require the source to be named where the data is used.
- **Docs:** `CLAUDE.md` `### Geo` describes all of the above.
- **Deviations:** "rapperswil" resolves to Rapperswil SG (the dataset has Ortschaften, no municipality names such as Rapperswil-Jona); the trigram index is on `geo_search_key(name)`, not `unaccent(name)`; `pg_trgm`'s weak-similarity `weak_search` was not adopted; the SVG came from the data.geo.admin.ch STAC item of the same BFS edition (full `Cantons_K4` layer, not the productive-area `kanton_vf`); `staleTownNames` reports `Name (CANTON)` because La Cibourg exists in BE and NE.
- **Rebase onto #2:** `tests/Application/Shared/Translation/FrenchLocaleProbeTest::test_parity_check_reports_the_untranslated_domain` now checks parity over the probe fixtures' domains only. It used to include `translations/`, which held only empty stubs; `geo` now has 26 keys and no `fr` catalogue, so the French probe kernel reported `fr/geo` as missing. The app-wide parity check (`CatalogueParityTest`, enabled locales only) is unchanged.
- **Carried forward:**
  - F-m1: the data sources must be credited on the public site: #10 shows the BFS credit near the canton map, #18 lists both credits in the Impressum ("Datenquellen").
  - F-m2: #20 re-checks the canton-map licence before launch: written confirmation of OPEN-BY from BFS GEOSTAT, or rebuild the SVG from swissBOUNDARIES3D (commercial use allowed, "© swisstopo"; option documented in `LICENSES.md`).
  - F-m4: #6 fixes `PlaceLookup` letting non-Latin input through: the guard checks the raw input, but e.g. `日本` folds to `''`, so `suggest('日本')` currently returns 8 cantons. Guard on the folded value (`q.de <> ''`) and add a test.
  - #6/#10: ranking by municipal population favours hamlets of large municipalities on 1–2 letter queries (`a` → Aldesago, part of Lugano, before Aarau; `ge` → Gerlikon second). A tie-break (Ortschaft name = municipality, or shortest name) may help.
  - The styleguide canton-map section (D9) goes to whoever lands after #1 (#10 at the latest).
  - #21's aliases (Genf, Davos Platz/Dorf); FR/IT canton names and town exonyms belong to the language cycles.
  - `symfony/string` and `symfony/translation-contracts` are used transitively (`symfony/translation` is declared since #2); a later cycle may declare them in `composer.json`.
  - #2 landed first and left `geo` to #3 (no stub), as planned. An integration assertion `trans('canton.zh', [], 'geo') === 'Zürich'` through the real translator is still open; the next cycle that touches Geo labels (#6 or #10) may add it.

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
  - `DemoStory` loads in under 60 s (raised from 10 s by the owner on 2026-10-30, because it missed under parallel lanes);
  - this file's Outcome lists every vocabulary code.

**Outcome (2026-10-30).** Done in commit `venue-schema`. No Composer changes, no routes, no Twig functions, no new Makefile targets or console commands. Read `CLAUDE.md` `### Venues` and `.claude/rules/database.md` (the last bullets) before touching the schema.

- **Migration `Version20261030114442`** (hand-written, full `down()`) creates 13 tables and seeds the 90 `taxonomy_terms` rows. Every table has `created_at`/`updated_at`; every FK column has its own `idx_<table>_<col>`, even inside a PK. CASCADE runs from `venues` to every child table, and from `rooms` to its photos and names.
  - **`venues`:** `id` UUID PK; `number INTEGER GENERATED ALWAYS AS IDENTITY` (`uk_venues_number`; the Merkliste id of S7, never the PK); `name` (150); `tier` (`chk_venues_tier`) and `tier_rank SMALLINT GENERATED ALWAYS … STORED` (beliebte_location 1 … basic 5; listings order by it); `status` (`published`/`unpublished`).
    - Capacity: `capacity_min`/`capacity_max` (`chk_venues_capacity`: max 1–50,000, min NULL or 1..max).
    - Address: `street`, `zip` (4 digits), `town_id` → `towns` RESTRICT, `canton_code` → `cantons` RESTRICT, `lat`/`lng` (CHECK: Swiss bounding box).
    - Distances: `distance_{station,highway,airport}_km NUMERIC(4,1)` 0–500, each with an optional `{station,highway,airport}_name` (a name only with its distance).
    - Contact: `phone` (E.164), `inquiry_email`, `website` (`^https?://`), `contact_person`.
    - FAQ: `own_catering` (`allowed`/`fixed_caterer`/NULL), `daylight` bool/NULL. `parking_text` is translated (see `venue_translations`).
    - Rating: `rating_score NUMERIC(3,1)` 0–10 and `rating_count` ≥ 0, both NULL together.
    - Room facts: `room_count`, `largest_room_m2`, `max_room_height_m NUMERIC(4,2)`. `max_room_height_m` is the height of the venue's **tallest** room. FI-4's "Min. Raumhöhe" filter (#6) compares `max_room_height_m >= :height`, just as "Grösster Raum" compares against `largest_room_m2`.
  - **`venue_translations`:** `venue_id`, `locale`, `slug` (CHECK `^[a-z0-9]+(-[a-z0-9]+)*$`, `uk_venue_translations_locale_slug`), `summary VARCHAR(200) NOT NULL`, `description TEXT`, `meta_description VARCHAR(160)`, `parking_text VARCHAR(250)`; `uk_venue_translations_venue_id_locale`.
  - **`venue_slug_history`:** `venue_id`, `locale`, `slug`; `uk_venue_slug_history_locale_slug`.
  - **`taxonomy_terms`:** PK `(term_group, code)`, `position` (unique per group), `primary_position` (unique per group, NULL allowed).
  - **`venue_terms`:** PK `(venue_id, term_group, term_code)`, `is_main` bool; `fk_venue_terms_term (term_group, term_code) → taxonomy_terms` RESTRICT; partial unique index `uk_venue_terms_venue_id_term_group_main … WHERE is_main` (at most one main term per venue and group); index `idx_venue_terms_term_group_term_code`.
  - **`venue_rating_highlights`:** PK `(venue_id, position)`, position 1–3, `code` (CHECK: the 9 codes), `uk_venue_rating_highlights_venue_id_code`.
  - **`venue_reviews`:** `venue_id`, `locale` (the language of the text), `first_name` (50), `role` (4 codes), `reviewed_month DATE` (CHECK: day 1), `text` (1000).
  - **`rooms`:** `venue_id`, `position` (unique per venue), `capacity_min/max`, `area_m2`, `height_m NUMERIC(4,2)`, `floor_material`, and `capacity_{seminar,banquet,apero,concert}_{min,max}` as columns. Each capacity is 1–50,000 and `min <= max` per pair. The room name and description are in **`room_translations`** (`room_id`, `locale`, unique per room and locale).
  - **`venue_documents`:** `venue_id`, `position` (unique per venue), `path` (CHECK `\.pdf$`, relative to `MEDIA_ROOT`), `size_bytes > 0`. The title is in **`venue_document_translations`**.
  - **`venue_photos`:** `venue_id`, `room_id` NULL → `rooms` CASCADE, `position`, `original_path`; `uk_venue_photos_venue_id_room_id_position UNIQUE NULLS NOT DISTINCT`. The alt text is in **`venue_photo_translations`** (`alt VARCHAR(700)`; a row only when there is a text). **#5 adds the variant columns.**
- **Vocabulary codes** (`App\Venues\ValueObject`, German labels in the `venues` catalogue `translations/venues+intl-icu.de.yaml`):
  - `TermGroup` (`term_group.<code>`): setting, building, suitable_for, infrastructure, furniture, technology, surroundings.
  - Terms (rows in `taxonomy_terms`, labels `term.<group>.<code>`, read with `App\Venues\ValueObject\Term`), in position order, with the eventlokale id for #21:
    - **setting** (Lage, 5): river 324, lake 323, countryside 325, mountains 326, city 327.
    - **building** (Gebäudeart, 39). The 11 primary buildings, shown before "Alle Gebäudearten", in chip order (`primary_position` 1–11): bar_club, event_venue, wedding_venue, hotel, party_room, restaurant, castle, congress_centre, forest_hut, farm_barn, mountain_hut. All 39 in position order (P*n* = `primary_position`):
      bar_club 328 (P1), farm_barn 337 (P10), mountain_hut 338 (P11), brewery_winery 340, medieval_castle 341, cafe 342, casino 343, digital_venue 1002, experience_creative 344, event_hall 345, event_venue 329 (P2), factory_hall 346, mobile_venue 347, leisure_facility 349, gallery_studio 350, garden_greenhouse 351, hangar_shipyard 352, historic_site 505, wedding_venue 330 (P3), cave_fortress 339, hotel 331 (P4), cinema 353, congress_centre 335 (P8), cultural_centre 354, exhibition_hall 348, museum 355, open_air 356, party_room 332 (P5), restaurant 333 (P6), ship 357, castle 334 (P7), seminar_room 1000, meeting_room 506, theatre 358, tram_train_bus 359, villa 360, forest_hut 336 (P9), tent 361, guild_hall 362.
    - **suitable_for** (Geeignet für, 21, wedding first): wedding 370, family_celebration 371, birthday 369, club_event 363, party_celebration 375, class_reunion 1015, private_event 372, meeting 367, conference 368, congress 364, corporate_event 365, general_assembly 366, apero 374, team_event 373, christmas_party 999, concert 376, theatre 377, party 1016, sports_event 1017, trade_fair 378, public_event 1018.
    - **infrastructure** (9): wifi 379, air_conditioning 380, stage 381, shower 382, cloakroom 383, heating_ventilation 384, kitchen 386, backstage_rooms 388, lectern 390.
    - **furniture** (Mobiliar, 5): flipchart 395, magnetic_board 397, moderation_kit 398, chairs 399, tables 401.
    - **technology** (Technik, 5): sound_system 402, projector_screen 403, interpreting_system 405, lighting_system 407, tv_screens 408.
    - **surroundings** (Umgebung, 6): garden 409, spa_wellness 410, terrace 411, playground 412, barbecue 413, golf 414.
  - The 10 enums:
    - `RatingHighlight` (`rating_highlight.<code>`, spec order; eventlokale `sla_rating` id → code; `icon()` returns the #1 sprite icon): food 1009 Feines Essen (`utensils`), atmosphere 1005 Stilvolle Atmosphäre (`sparkles`), architecture 1004 Einzigartige Architektur (`landmark`), location 1007 Einmalige Lage (`binoculars`), hospitality 1011 Freundliche Gastgeber (`smile`), reachability 1008 Perfekte Erreichbarkeit (`route`), equipment 1006 Moderne Ausstattung (`sofa`), programme 1010 Attraktives Programm (`music`), value 1012 Top Preis/Leistung (`coins`).
    - `Tier` (`tier.<code>`, `rank()`): beliebte_location 1, empfehlung 2, top_premium 3, premium 4, basic 5 ("Standard", no badge).
    - `RatingLabel` (`rating_label.<code>`, `fromScore()`): recommended 7.7–7.9, excellent 8.0–8.9, outstanding ≥ 9.0; below 7.7 gives no label.
    - `Access` (`access.<code>`, Erreichbarkeit; derived from the distances, not stored; #6 sets the thresholds): station, highway, airport.
    - `OwnCatering` (`own_catering.<code>`): allowed, fixed_caterer.
    - `FloorMaterial` (`floor_material.<code>`): various, parquet, carpet, stone, wood.
    - `Seating` (`seating.<code>`): seminar, banquet, apero, concert.
    - `ReviewerRole` (`reviewer_role.<code>`): visitor_f, visitor_m, organiser_f, organiser_m (eventlokale `owner_*` → `organiser_*`).
    - `VenueStatus` (not translated): published, unpublished.
    - `TermGroup` (above).
  - Setting icons for #8 (#1's sprite): river `waves`, lake `sailboat`, countryside `trees`, mountains `mountain-snow`, city `building-2`. They are not in code, because settings are rows.
- **Entities and repositories:**
  - `App\Venues\Entity\{Venue, VenueTranslation, VenueSlugHistory, TaxonomyTerm, VenueTerm, VenueRatingHighlight, VenueReview, Room, RoomTranslation, VenueDocument, VenueDocumentTranslation, VenuePhoto, VenuePhotoTranslation}` (Doctrine mapping `Venues` in `config/packages/doctrine.yaml`).
  - `App\Venues\Repository\VenueRepository`, `App\Venues\Repository\TaxonomyTermRepository` (`byGroup()`).
  - `App\Venues\Exception\RoomOfAnotherVenue` (a photo's room must belong to the same venue; checked in the `VenuePhoto` constructor only).
  - The `Venue` write API has no setters (see `CLAUDE.md` `### Venues`).
- **Mapping resolutions** (recorded in `.claude/rules/database.md`):
  - R1: the identity `number` is mapped `insertable: false, updatable: false, generated: 'INSERT'`. No extra option and no listener was needed.
  - R2: Doctrine refuses a composite-key association inside a primary key. `VenueTerm` therefore has read-only scalar `#[ORM\Id]` fields `termGroup`/`termCode` (getters `termGroup()`/`termCode()`) plus a non-id, writing `ManyToOne term` with two JoinColumns on the same columns, which keeps `fk_venue_terms_term`. The `term` association is set once in the constructor and must never be reassigned: to change a venue's term, delete the row and insert a new one.
  - R3: the partial unique index is mapped with `where: 'is_main'`.
  - `App\Tests\Integration\Migrations\SchemaInSyncTest` runs `doctrine:schema:validate` inside `make test`; `VocabularyChecksTest` pins each enum to its CHECK list.
- **Demo data:**
  - 12 Foundry factories in `src/Factory/` (`App\Factory\{Venue, VenueTranslation, VenueSlugHistory, VenueTerm, VenueRatingHighlight, VenueReview, Room, RoomTranslation, VenueDocument, VenueDocumentTranslation, VenuePhoto, VenuePhotoTranslation}Factory`). There is no `TaxonomyTerm` factory, because the migration seeds the terms. The faker locale is `de_CH` (`zenstruck_foundry.yaml`); faker texts get ß → ss.
  - `App\Story\DemoStory` (`DemoStory::VENUES` = 60): 60 fictional, all published venues in all 26 cantons; tiers beliebte_location 3, empfehlung 6, top_premium 9, premium 12, basic 30; 1–5 rooms, 0–3 reviews, 0–2 documents, 20 without a rating, no photos; one main building and one main setting per venue. It is deterministic (faker seed `20261030`, index-based distribution) and idempotent (does nothing when venues exist), and needs the towns.
  - `make seed` runs `app:geo:import-towns`, then `foundry:load-fixtures main --append`; `App\Story\AppStory` loads `DemoStory`. The story loads in about 2.2–2.5 s (budget 10 s).
- **Deviations from the spec:**
  - no `venues.slug`: the slug lives in `venue_translations` (one per locale, S4);
  - `venue_terms.is_main` instead of `venues.main_building_type`;
  - `taxonomy_terms.primary_position` instead of `is_primary_filter` (it also gives the chip order);
  - `max_room_height_m` instead of `min_room_height_m` (see the `venues` bullet for its meaning);
  - column `term_group`, not `group` (a reserved word);
  - `venue_document_translations` / `venue_photo_translations` instead of `document_translations`;
  - eventlokale's `accesses` vocabulary is not copied: Erreichbarkeit is derived from the distances;
  - an unknown tier is rejected first by `tier_rank`'s NOT NULL (the CHECK stays);
  - the R2 mapping variant above.
- **Carried forward:**
  - #9: a content-key column on `venues` (e.g. `content_key`) in its own migration. The write side validates locales with `EnabledLocale::isEnabled()`, keeps history slugs distinct from current slugs, and computes `room_count`/`largest_room_m2`/`max_room_height_m`.
  - #21: works out what the thumbs-up count means (the `rating_count` source: `likes` vs. comments) and the tier mapping, and cleans the data: 240 venues with capacity 0, 8 with 0/0 coordinates, summaries over 200 characters, outlier heights and areas. Use the id → code tables above.
  - #6: search indexes beyond the FK indexes; the Erreichbarkeit thresholds (`Access`).
  - #5: the `venue_photos` variant columns and demo photos.
  - `canton_code` is derived from the town in the `Venue` entity; the DB does not enforce that it matches the town's canton.
  - `.claude/rules/i18n.md` still says `document_translations`; the real name is `venue_document_translations` (docs-only fix for whoever next touches that file).

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

**Outcome (2026-10-30).** Done in commit `venue-media`. No Composer or Dockerfile change, no routes, no new Makefile targets. Built on gd (the PHP image already encodes AVIF). Read `CLAUDE.md` `### Media` before touching images.

- **Migration `Version20261030125302`** (full `down()`) adds four columns to `venue_photos`. #5 owns them; #6 owns only the indexes on the table. `id`, `venue_id`, `room_id`, `position`, `original_path` are unchanged. No backfill (no rows existed); a photo row is written only after its variants exist.
  - `width INTEGER NOT NULL` (`chk_venue_photos_width`, > 0) and `height INTEGER NOT NULL` (`chk_venue_photos_height`, > 0): the **EXIF-oriented original's** pixel size. Use them for `<img width height>` (aspect ratio), not the variant size.
  - `dominant_color VARCHAR(7) NOT NULL` (`chk_venue_photos_dominant_color`, `^#[0-9a-f]{6}$`, lower case): the placeholder colour.
  - `variants JSONB NOT NULL` (`chk_venue_photos_variants`: a `CASE` that rejects anything but an object with a non-empty `widths` array and a `formats` array containing `"jpg"`), shape exactly `{"widths": [360, 640, 960, 1280], "formats": ["avif", "webp", "jpg"]}`.
    - `widths`: ascending ints, the subset of 360/640/960/1280/1920 not wider than the original (no upscaling), or `[<original width>]` when the original is narrower than 360.
    - `formats`: preference order avif, webp, jpg; `jpg` always, avif/webp only if the encoding host supported them. Every width exists in every listed format.
    - The CHECK checks the shape only (`{"widths": ["x"], …}` passes the DB); `ImageVariants::fromArray()` rejects it on read.
- **Files and URLs:**
  - Originals under `MEDIA_ROOT` (env; `.env` `%kernel.project_dir%/var/media`, `.env.test` `var/test-media/originals`). `venue_photos.original_path` and `venue_documents.path` are relative to it.
  - Variants: `public/media/<venues.number>/<venue_photos.id>-<w>.<avif|webp|jpg>`, URL `/media/<venue-number>/<photo-id>-<w>.<ext>`. Documents: `public/media/<number>/<venue_documents.id>.pdf`, URL `/media/<number>/<id>.pdf`.
  - Parameters `app.media_root` and `app.media_public_dir` (`config/services.yaml`; `when@test`: `var/test-media/public`). `/public/media/` is git-ignored; nginx serves it statically with the right MIME types.
- **Media module** (depends on nothing):
  - `App\Media\ImageVariantGenerator::generate(string $sourceFile, string $targetDirectory, string $basename): GeneratedImage`. Constants `WIDTHS`, AVIF q52 speed 6, WebP 80, progressive JPEG 82, `SETTINGS_VERSION`; `availableFormats()`, `formats()`, `fingerprint()`. Applies EXIF orientation, strips metadata and ICC, flattens alpha onto white, detects formats at runtime, verifies every file, writes `.tmp` + rename, removes stale widths.
  - `App\Media\MediaPublisher` (`copyVariants()`: direct copy, new photo ids only; `publishDocument()`: `.tmp` + rename; `removeVenue()`) and `App\Media\MediaPaths` (`original()` validates the path, `variantFile()`, `documentFile()`, `documentUrl()`, `mediaRoot()`, `publicDirectory()`, `URL_PREFIX = '/media'`).
  - Value objects `App\Media\ValueObject\{ImageFormat, ImageVariants, GeneratedImage, Picture}`. `ImageVariants` has `fromArray`/`fromJson`/`toArray`/`has`/`smallest`/`largest`.
  - Exceptions `App\Media\Exception\{MissingOriginal, UnreadableImage, InvalidMediaPath}` (domain exceptions; an empty file is `UnreadableImage`). An encoder failure is a `\RuntimeException`.
- **For read models (#6/#7/#8):** select `p.id, p.position, p.width, p.height, p.dominant_color, p.variants`, plus `v.number` and the alt via `TranslationJoin` on `venue_photo_translations`; build `new Picture(int $venueNumber, string $photoId, int $width, int $height, string $dominantColor, ImageVariants::fromJson($row['variants']), string $alt)`. Without an alt row use `'picture.alt_fallback'|trans({name, position}, 'media')` ("{name}, Bild {position}").
- **Twig** (`App\Media\Twig\MediaExtension`, styles `assets/styles/media/_picture.scss`):
  - `{{ venue_picture(picture, sizes='(min-width: 900px) 520px, 100vw', loading='lazy', priority=false, widths=null, class='') }}` renders `<span class="picture"><svg class="picture__placeholder" aria-hidden="true"><rect … fill="#rrggbb"/></svg><picture><source type="image/avif" srcset…><source type="image/webp" srcset…><img class="picture__img" width height …></picture></span>`. The placeholder is an SVG, not an inline `style`, so it is CSP-safe (no `[style]` attributes). Only listed formats get a `<source>`.
  - Cards pass `widths=[360, 640, 960]` (listing HTML budget). The LCP image gets `priority=true` (`loading="eager" fetchpriority="high"`); LP-3's second card uses `loading='eager'` without priority. `loading` other than lazy/eager throws.
  - `{{ document_url(venueNumber, documentId) }}` returns `/media/<number>/<id>.pdf`.
- **Venues:**
  - `VenuePhoto(Venue, ?Room, int $position, string $originalPath, GeneratedImage $image)`, `recordVariants(GeneratedImage)`, getters `width()`, `height()`, `dominantColor()`, `variants()`. `VenueRepository::numbers(?int $only = null): list<int>`.
  - `VenuePhotoFactory` writes rows only (default 1600×1067, widths 360–1280, all formats), no files.
  - `app:media:regenerate [--venue=<number>]` (`App\Venues\Console\RegenerateMediaCommand` → `App\Venues\Command\RegenerateMedia` → `App\Venues\CommandHandler\RegenerateMediaHandler`, result `App\Venues\ValueObject\RegenerateMediaResult`) re-renders the variants and re-publishes the documents; continues past missing originals, exits 1 on any failure. It rewrites the same URLs, so #19 must not mark `/media` `immutable`.
- **Demo data** (`App\Story\DemoMedia`, used by `DemoStory`):
  - Every venue gets 3–8 gallery photos (every tenth venue 8) and every third venue 1–2 photos in its first room: 390 photos. They come from 30 gd-drawn originals (`MEDIA_ROOT/demo/photo-01.jpg` … `photo-30.jpg`: gradient, translucent shapes, the venue initial; numbers 5, 10, …, 30 are 2000 px wide, so 1920 exists; ratios 3:2, 4:3, 16:9, 2:3, 1:1). Odd positions have a German alt row; even ones exercise the fallback.
  - Every document has a real one-page PDF; `size_bytes` is its size.
  - #4's venue data is byte-identical (photos are created in a second loop after all 60 venues, with their own seeded RNG).
  - Variants are cached per original in `var/demo-media/` (git-ignored). Seed times (M4 Pro, bind mount): cold first `make seed` ~22 s (21.9), warm ~9.6 s (the #4 seed was 3.6 s); `DemoStory` alone 7.2–7.5 s warm (budget 10 s).
  - Demo 640 px AVIFs are 2.4–5.1 KB (budget 40 KB). `public/media` holds 47 MB / 4,673 files, `var/demo-media` 3.7 MB.
- **Measured:** about 2.1–2.3 CPU-s and 1.56 MB of variants per 15 MP photo (research sample).
- **Tests:** `tests/Unit/Media/`, `tests/Integration/Media/{ImageVariantGeneratorTest,MediaPublisherTest}.php` (dimensions, orientations 1/2/3/6/8, metadata stripping, peak memory), `tests/Application/Media/VenuePictureTest.php`, `tests/Integration/Venues/RegenerateMediaCommandTest.php`, helpers `App\Tests\Support\Media\{TestImages, MediaFiles}` (`MediaFiles::removeDirectory()`). A test that builds `DemoStory` must clean `MediaPaths->publicDirectory()` in `tearDown()`. 449 tests in total.
- **Deviations:**
  - `variants` is an object `{widths, formats}`, not a list (all widths share one format set).
  - `document_url(venueNumber, documentId)` takes two scalars instead of `document_url(doc)` (Media cannot know `VenueDocument`).
  - `venue_picture()` takes a `Picture` view object (not the entity) and has extra `widths` and `class` parameters; the alt fallback is in the `media` catalogue, not `venues`; no `focusable` on the SVG; no styleguide example (#7/#8).
  - The `variants` CHECK is a `CASE … ELSE FALSE END` (the plan's AND chain let a missing key through as NULL).
  - `copyVariants()` copies directly, without `.tmp` + rename (hard links measured slower); the generator and `publishDocument()` keep `.tmp` + rename.
  - The white canvas uses `imagefilledrectangle()` instead of `imagefill()` (flood-fill memory).
  - The demo palette is computed (HSL), not a literal list; `DemoMedia::variants()` is memoised per process.
- **Carried forward:**
  - **#20:** verify AVIF on Hostpoint over SSH (`/usr/local/php85/bin/php -r 'var_dump(imagetypes() & IMG_AVIF, imagetypes() & IMG_WEBP);'`, `php -m | grep -E "gd|imagick|exif"`, `memory_limit ≥ 256M`). Recommended: generate the variants locally (Docker image with AVIF) and rsync `public/media`; check that ~7–15 GB of variants fit the disk quota (first lever: fewer JPEG widths). Plan B if gd lacks AVIF but Imagick has it: Imagick inside the one generator class.
  - **#21:** measure encode time and size on the real originals from the SSD; count CMYK and non-sRGB originals (gd ignores ICC; slight shifts accepted). AVIF speed 8 is the lever if the bulk encode is too slow.
  - **#9:** copy the original into `MEDIA_ROOT`, `generate()` into a staging directory, create the `VenuePhoto` with the result, then `MediaPublisher::copyVariants()` (new photo ids only; a changed original gets a new id); documents via `publishDocument()`.
  - **#7:** map #6's `CardPhoto` to `Picture` (alt fallback key `picture.alt_fallback` in `media`).
  - **#6** (not yet landed): its `ListingQueryPerformanceTest` must build `DemoStory` from the container (it now also needs `DemoMedia`) and clean `MediaPaths->publicDirectory()` in `tearDown()`; expect `make test` to grow by ~30 s. Migration order with #6's `Version20261030115117` does not matter (indexes vs. columns).
  - EXIF orientations 4/5/7 are untested (code-checked only).
  - `DemoStory` clears `public/media/<n>/` only for numbers 1–60; stale media directories of higher venue numbers from older dev databases stay (dev only, harmless).

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

**Outcome (2026-10-30).** Done in commit `search-query`, rebased onto #5. No routes besides the two JSON endpoints, no Twig functions, no catalogue domains, no new Makefile targets or console commands. Composer: `symfony/clock: 7.4.*` declared (it was already installed transitively; the lock changed only its content hash). Read `CLAUDE.md` `### Search` before touching the listing query.

- **Migration `Version20261030115117`** (indexes only, full `down()`): `idx_venues_earth` (GiST on `ll_to_earth(lat, lng)`) and `idx_venues_search_key` (GIN trigram on `geo_search_key(name)`). Both are unmapped expression indexes, like Geo's (`Venue`'s docblock says so). No btree on capacity, rooms, area or height (not selective). Its timestamp is older than #5's `Version20261030125302`; the order does not matter (indexes vs. columns).
- **Evidence-settled answers** (eventlokale live, 15 GETs on 2026-10-30):

  | Question | Decision |
  |---|---|
  | Lage `setting[]` | OR (am_see + auf_dem_land → 573; OR ≈ 607, AND ≈ 89) |
  | Gebäudeart `building[]` | OR (302; OR ≈ 304, AND ≈ 1) |
  | Infrastruktur `infra[]` | AND (218; AND ≈ 233, OR ≈ 715) |
  | Umgebung `surroundings[]` | AND (76; AND ≈ 81, OR ≈ 582) |
  | Mobiliar `furniture[]`, Technik `tech[]` | AND, inferred (same bundle loop as infra/surroundings; not measured) |
  | Erreichbarkeit `access[]` | multi-value AND (our decision; eventlokale is single-select) |
  | Between groups | AND |
  | Guests | `capacity_max ≥ g AND (capacity_min IS NULL OR capacity_min ≤ g)` (`personen=10` → 680; min ≤ g ≤ max ≈ 684 vs max-only ≈ 918) |
  | Radius | default 5 km once a place is picked, 0–50 km in whole km, 0 = the place only; measured on the venue's coordinates from the town centre (a ZIP's centre is its most populous town); an unknown place matches nothing |
  | Erreichbarkeit thresholds | station ≤ 1 km, highway ≤ 10 km, airport ≤ 30 km; NULL distance = not near (`App\Venues\ValueObject\Access::maxDistanceKm()`) |
  | Anzahl Räume `rooms` | 1–6 (6 = "≥ 6"), `room_count ≥ n` |
  | Grösster Raum `area` | 50, 100, 150, 200, 250, 300 m², `largest_room_m2 ≥ n` |
  | Min. Raumhöhe `height` | 2.5, 3.0, 3.5, 4.0, 4.5, 5.0 m, `max_room_height_m ≥ n` |

- **`App\Search\ReadModel\SearchCriteria`** (`final readonly`, hotspot owned by #6; filter UIs only render it). Build it with `none()`, `fromQuery(InputBag $query, KnownTerms $known)`, `withPage(int)`, `withCanton(?Canton)`; read `termCodes(TermGroup)`, `isZipPlace()`. Constants `PARAMS` (param → `TermGroup`), `DEFAULT_RADIUS` 5, `MAX_RADIUS` 50, `AREAS`, `HEIGHTS`, `MAX_PAGE` 1000, `MAX_GUESTS` 50000, `MAX_ROOMS` 6.
  - **Grammar of `fromQuery()`.** Invalid values are dropped silently, never a 4xx/5xx. It reads `$query->all()` once and type-checks every value (`InputBag::get()`/`all($key)` would answer a scalar/array mismatch with a 400).
    - `canton`: one code, case-insensitive.
    - `place`: a town UUID (lower-cased) or a 4-digit ZIP. `radius` 0–50, kept only with `place`, default 5.
    - `guests`: 1–50000.
    - `q`: trimmed, whitespace collapsed, ≤ 100 chars, needs `\p{L}\p{N}`, invalid UTF-8 dropped. The SQL also guards `geo_fold_de(:q) <> ''`, so `日本` matches nothing.
    - `setting[] building[] infra[] furniture[] tech[] surroundings[]`: known `taxonomy_terms` codes of the matching group only (`infra` → `infrastructure`, `tech` → `technology`; `TermGroup`'s docblock says so). A scalar is taken as a one-element list; nested arrays are skipped. `suitable_for` is not a listing filter.
    - `access[]`: `station|highway|airport`.
    - `rooms`, `area`, `height`: the dropdown values above. `page`: 1–1000.
  - **`toQuery()` is canonical:** key order `canton, place, radius, guests, q, setting, access, building, infra, furniture, tech, surroundings, rooms, area, height, page`; sorted, deduplicated lists; `height` with one decimal; page 1 and radius 5 omitted. One filter state = one URL (the S10 cache relies on it). Build URLs with `http_build_query($criteria->toQuery())`.
  - **`KnownTerms`** (`of()`, `fromPairs()`, `has(TermGroup, code)`, `count()`) is the vocabulary the codec checks against; **`KnownTermsQuery::all()`** loads the 90 pairs once per request (memoized, `ResetInterface` / `kernel.reset`).
- **`App\Search\ReadModel\ListingQuery`** (DBAL): `results(SearchCriteria, string $locale): ListingPage`, `count(SearchCriteria): int`, `PER_PAGE` 48, plus `cardStatement()`/`countStatement()` (return `SqlStatement` {`sql`, `params`, `types`} for EXPLAIN).
  - **`ListingSql`** is the one WHERE / `WITH centre` / `FROM` builder shared by cards, count and similar venues, so they can never disagree. Published = `status = 'published'` **and** a default-locale (`de`) translation. The radius centre must stay a joined `MATERIALIZED` CTE (`CROSS JOIN centre c`), or the planner skips the GiST.
  - Order (S6): `tier_rank, md5(id || seed), id`, seed = today in Europe/Zurich via `Psr\Clock\ClockInterface` (`MockClock` in tests): stable within a Zurich day, changes at Zurich midnight.
  - Exactly 3 queries (cards, photos, count), 2 for an empty page, proved via `doctrine.debug_data_holder`. The codec's `KnownTermsQuery` is a fourth, memoized query.
  - **`ListingPage`** {`cards: list<ListingCard>`, `total`, `page`, `perPage`, `lastPage`} (`of()`). **`ListingCard`** {`id`, `number`, `slug`, `name`, `tier: Tier`, `capacityMin: ?int`, `capacityMax`, `townName`, `summary`, `ratingScore: ?float`, `ratingCount: ?int`, `highlights: list<RatingHighlight>`, `photos: list<CardPhoto>` (≤ 8)}. Highlights come as `jsonb_agg`.
  - **`CardPhoto`** {`venueNumber`, `photoId`, `position`, `originalPath`, `width: ?int`, `height: ?int`, `dominantColor: ?string`, `variants: ?array{widths, formats}`, `alt: ?string`}, `hasVariants()`. Read from `to_jsonb(venue_photos)`: the first 8 **venue-level** photos (`room_id IS NULL`) in position order, so #5's columns appear without an SQL change. Search does not reference Media classes.
- **#7 maps `CardPhoto` to `App\Media\ValueObject\Picture`** (the `venue_photos` columns are on main since #5): only when `$p->hasVariants()`, `new Picture($p->venueNumber, $p->photoId, $p->width, $p->height, $p->dominantColor, ImageVariants::fromArray($p->variants), $p->alt ?? 'picture.alt_fallback'|trans({name: card.name, position: p.position}, 'media'))`, rendered with `venue_picture(…, widths=[360, 640, 960])`.
- **`App\Search\ReadModel\SimilarVenuesQuery::forVenue(string $venueId, string $locale): list<SimilarVenue>`** (`LIMIT` 12): other published venues ordered by same canton → capacity overlap (`v.max ≥ COALESCE(me.min,1) AND COALESCE(v.min,1) ≤ me.max`; ranks, does not filter) → `earth_distance` → id. `SimilarVenue` {`id`, `number`, `slug`, `name`, `tier`, `townName`, `capacityMin`, `capacityMax`, `photo: ?CardPhoto`} (first venue photo). An invalid or unknown id gives `[]`.
- **Endpoints** (`App\Search\Controller\{CountController, PlacesController}`), unlocalized (`_` prefix), `X-Robots-Tag: noindex`, no session, no cookie (application-tested):
  - `_search_count`, `GET /_search/count?<listing query>` → `{"count": n}`, `Cache-Control: public, max-age=60, s-maxage=600, stale-while-revalidate=86400`. Garbage gives 200 with the unfiltered count.
  - `_search_places`, `GET /_search/places?q=` → `{"places": [{"type":"canton","canton":"zh","label":"Zürich"} | {"type":"town","id","name","zip","canton"}]}`, `Cache-Control: public, max-age=3600, s-maxage=86400`. Backed by Geo's `PlaceLookup`.
- **Performance** (`tests/Integration/Search/ListingQueryPerformanceTest.php`: DemoStory ×10 = 600 venues, 6,000 photos cloned by SQL in a rolled-back transaction, `ANALYZE`; PostgreSQL 18.6; `EXPLAIN ANALYZE` Execution Time, second run; `SEARCH_PERF_REPORT=1` prints them). Budget 20 ms.

  | Statement | ms | Plan uses |
  |---|---|---|
  | Heaviest, literal values (0 matches), cards / count | 0.23–0.29 / 0.14–0.26 | `idx_venues_earth`, `pk_venue_terms` (index-only), `idx_venue_terms_venue_id` |
  | Heaviest, matching variant (10 matches), cards / count | 0.56–0.83 / 0.38–0.51 | same |
  | Unfiltered page 11 (offset 480), cards / count | 3.3–4.6 / 0.22–0.39 | seq scan + sort (parameterised md5 seed, unavoidable) |
  | Photo query, 48 cards × ≤ 8 | 1.4–1.8 | profiler incl. round trip; reported, not asserted |
  | Radius 50 km only (seqscan + indexscan off) | 2.9–3.3 | `idx_venues_earth` |
  | `q=hof` only (seqscan + indexscan off) | 2.1–4.8 | `idx_venues_search_key` |
  | `setting[]=mountains` only (seqscan + indexscan off) | 2.2–2.4 | `idx_venue_terms_term_group_term_code` |

  "Heaviest" = radius 50 km around Zürich, guests 80, `q=hof`, access station, rooms 2, area 100, height 2.5, the 3 most used settings, the 4 most used buildings, the most used code of each AND group. The class has two tests (budget; the three index checks, each in a savepoint), so it builds DemoStory ×10 twice; it gets `DemoStory` from the container and removes `%app.media_public_dir%` in `tearDown()`.
- **Tests:** `tests/Unit/Search/{SearchCriteriaTest,CardPhotoTest}.php` (round trip, canonical form, ~45 garbage cases), `tests/Unit/Venues/AccessTest.php`, `tests/Integration/Search/{SearchIndexesMigrationTest,KnownTermsQueryTest,ListingQueryTest,SimilarVenuesQueryTest,ListingQueryPerformanceTest}.php`, `tests/Application/Search/{CountControllerTest,PlacesControllerTest}.php`. Read models are built by hand in tests (`TranslationJoin` is removed from the test container; `ListingQueryTest::enabledLocale()`).
- **Closed carried items:** #0 F-m1 (`because()` wording) and F-m2 (`self::layer('Repository')` in `InfrastructureAccessTest`); #3 F-m4 (`PlaceLookup` guards the folded value, `WHERE q.de <> ''`: `日本`/`Ελλάδα` → []); #3 short-query ranking (≤ 2 folded chars rank by `least(zip_count, 3)` first: `a` → Aarau, `ba` → Basel); #3 optional `trans('canton.zh', [], 'geo') === 'Zürich'` (asserted in `PlacesControllerTest`); #4 Erreichbarkeit thresholds (`Access::maxDistanceKm()`); `symfony/clock` declared.
- **Deviations:**
  - Highlights as `jsonb_agg`, not `ARRAY(…)` (DBAL returns a PG array literal).
  - "Published" includes a default-locale translation, in card and count query.
  - Performance assertions split: budget on heaviest/count/page 11; per-index usability with seqscan **and** indexscan off (with seqscan off alone the planner flipped between the GIN and an unrelated btree full scan at 600 rows). A second "matching" heaviest variant was added because the literal values match 0 DemoStory venues.
  - `SimilarVenuesQuery` orders canton → capacity overlap → distance, not the spec's "same canton first, then nearest by distance, capacity overlap": distance is continuous, so overlap after distance would never break a tie; before distance it actually ranks.
  - `KnownTerms::count()` added; `KnownTermsQuery` orders by `term_group, position`.
- **Hand-offs:**
  - **#7:** the `CardPhoto` → `Picture` mapping above; answer 404 when `page > ListingPage::lastPage`; paginate with `withPage()`; URLs via `http_build_query($criteria->toQuery())`.
  - **#8:** `SimilarVenuesQuery::forVenue()` (DP-15) and `Access::maxDistanceKm()` (DP-10).
  - **#10:** the places JSON shape above and `_search_count` for the live overlay count. **#11/#12** use `_search_count` the same way.
  - **#12:** access thresholds from `Access::maxDistanceKm()`; new term filters are AND unless the group is setting/building.
  - **#16:** `Disallow: /_search/` in robots.txt.
  - **#17:** `SearchCriteria::withCanton()`.
  - **#19:** retune the cache values (count 60/600/swr 86400; places 3600/86400).
- **Carried forward:**
  - Demo originals stay in `var/test-media/originals` after tests (`DemoMedia` copies the 30 originals there; git-ignored, #5's `DemoStoryTest` leaves them too).
  - `composer validate --strict` fails on the pre-existing missing `name`/`description` in `composer.json` (plain `validate` passes).
  - A NUL byte in `q` is truncated by the driver (`?q=a%00b` counts like `?q=a`); harmless, the codec does not strip control characters.

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

**Outcome (2026-10-30).** Done in commit `listing-page`. No migration, no table, no new package, no Makefile target or console command. Catalogue domains `search` (`listing.*`, `filter.*`, `chip.*`, `card.*`, `pagination.*`, `related.*`) and `favorites` (`merken.label`, `merken.label_detail`, `merken.label_for`) extended, none added. Read `CLAUDE.md` `### Search` ("Built in #7") and `.claude/rules/frontend.md` (Slots, Components) before touching the page.

- **Routes:**
  - `listing` `GET /hochzeitslocations` (localized `['de' => …]`) → `App\Search\Controller\ListingController`. Filters come from the query string through #6's `SearchCriteria::fromQuery()`; invalid values are ignored, an invalid `page` is page 1, `page > lastPage` → 404. 48 cards per page (`ListingQuery::PER_PAGE`). `#[Cache(public: true, smaxage: 600, staleWhileRevalidate: 86400)]`; no session, no `Set-Cookie`, no `X-Robots-Tag` from the controller (dev/test add `noindex` themselves via `kernel.debug`). 4 queries per page (results, count, known terms, `TopCantonsQuery`).
  - `home` `/` is a YAML route in `config/routes.yaml`: framework `RedirectController`, 302 → `listing`, `keepQueryParams`, `ignoreAttributes: [_canonical_route]`. `App\Shared\Controller\HomeController` and `templates/site/placeholder.html.twig` are deleted.
  - **Canonical:** `<link rel="canonical">` = `url('listing')`, plus `?page=n` for n > 1; filters are not in it (#16 owns the final rules and `noindex,follow` for filter combinations). No `rel=prev/next`.
- **Classes:**
  - `App\Search\Twig\ListingExtension` (Twig functions): `listing_path(SearchCriteria criteria, ?int page = null): string` (the filter state's URL via `toQuery()`); `listing_path_without(SearchCriteria criteria, string|list<string> params, ?string value = null): string` (drops whole params or one value of a list param, always drops `page`; the chips' remove links); `card_picture(CardPhoto photo, string venueName): ?Picture` (#6's CardPhoto → `Media\ValueObject\Picture` recipe, alt fallback `picture.alt_fallback`; null without variants); `rating_label(float score): ?RatingLabel`; `venue_path(string slug): string` (= `generate('venue_show', {slug})` since the #7/#8 reconciliation).
  - `App\Search\ReadModel\TopCantonsQuery::top(int $limit = 9): list<CantonCount>` (published venues per canton, on #6's `ListingSql`); `App\Search\ReadModel\CantonCount` (`canton`, `count`).
  - `App\Search\ValueObject\PageLinks::around(int $page, int $lastPage): list<int|null>` (pagination window "1 2 3 … 20", null = gap).
- **Templates and their params** (include `with_context = false` / `only`):
  - `search/listing.html.twig` (page) → `search/_hero.html.twig` (`heading`, `total`, `criteria`; h1 + "N Resultate" + the filter bar) → `search/_filter_bar.html.twig` (`criteria`; #7 hotspot: chips row, the three slots, the round "Merkliste" button `variant: 'round'` with visible label).
  - Slot files `search/filter/_region.html.twig`, `_guests.html.twig`, `_more.html.twig` (`criteria`, `part: chips|trigger`; each renders `<li class="filter-bar__item">`s: its chips, or its trigger + an empty `components/_overlay` shell). **#10, #11, #12 own one file each and never edit `_filter_bar`.** `search/filter/_hidden_state.html.twig` (`criteria`, `except`: list of params the form submits itself; never carries `page`) is for their GET forms.
  - `search/_card.html.twig` (`card`, `index`, `action: merken|none`; #13 adds `remove`; #8's similar list and #13's `/merkliste` reuse it). Photo carousel (#1 `components/_carousel`), `index` drives #5's `venue_picture` priority (card 0's first photo is the LCP image, card 1's first photo eager, everything else lazy), badge `components/_badge`, title link stretched over the card, **each slide wrapped in its own `a.card__photo-link` (`tabindex="-1"`, `aria-hidden="true"`)** so a photo tap opens the venue while swipe/arrows page. `search/_card_facts.html.twig` (`capacity_min`, `capacity_max`, `town_name`), `search/_card_rating.html.twig` (`score`, `count`, `highlights`).
  - `search/_pagination.html.twig` ("1 - 48 von N Resultaten" + `PageLinks`), `search/_empty.html.twig` ("Keine Location passt zu deinen Filtern" + "Filter löschen"), `search/_related_searches.html.twig` (`links`: list of `{label, href}` with translated labels, `title`; "Andere suchten auch nach" from `TopCantonsQuery`, #17 passes landing links).
  - `favorites/_merken_button.html.twig` (`number`, `name`, `label`, `variant: card|detail`): the S7 no-JS `<form class="merken merken--{variant}" method="post" action="/merkliste/{number}">` with `button.merken__button[data-merken][data-merken-name][aria-pressed]`; literal action and no CSRF until **#13**, which owns the file from now on.
  - `base.html.twig` loads `js/overlay.js`; the main menu is a `[data-overlay]` (focus wrap). The header search link is `path('listing')`.
- **Styles:** `assets/styles/search/_{listing,card,filter-bar,pagination,related}.scss`, `assets/styles/favorites/_merken.scss` (incl. the `--detail` variant used by #8's bottom bar), `@use` lines appended to `app.scss`.
- **Gates:** Lighthouse 13.5 (mobile) **prod-like 100 / 100 / 100 / 100** (LCP 1.5 s, CLS 0.026, TBT 0 ms; `var/lighthouse/20261030-144234-…`). **Method (reuse it):** in the lane, a git-ignored `.env.local` with `APP_ENV=prod` + `APP_DEBUG=0`, `cache:clear`, `sass:build`, `asset-map:compile`, check `curl -sI` (200, no `X-Robots-Tag`), `make lighthouse URL=/hochzeitslocations`; then delete `.env.local` and `public/assets/`, `make cache-clear`. Dev scores 100/100/100/66 (only `is-crawlable`, dev noindex). HTML: worst case 48 cards × 8 photos **31,564 B gzip** (`ListingHtmlBudgetTest`, budget 61,440), live demo page 37,233 B gzip -9 (505,172 raw). `app.css` 21.6 KB raw (≤ 25 KB gzip, `AssetBudgetTest`); page JS `overlay.js` 1.6 KB + `carousel.js` 2.2 KB raw. vnu (`make validate-html`) no errors on `/hochzeitslocations`, `?canton=zh&guests=120&setting[]=lake`, `?page=2`, `?q=zzzzzz`. Screenshot comparison at 1440/1024/768/390/320 px matches `listing-page-top.jpg` / `listing-card.png` (no "Karte" button, no "Locations" pill, no event chip per LP-1); no overflow at 320 px.
- **Deviations:** **gzip on in `docker/nginx/default.conf`** (text types, level 5, min 1024; without it the prod-like run scored Performance 78 on the 475 KB uncompressed HTML); static meta description `listing.meta_description` (for #16); one chip for place + radius (shows "Ort" until #10 resolves town names); styleguide overlay count 3 → 4; filter-bar Merkliste → `path('home')`; triggers styled as text items with divider lines; Merken heart in a dark circle below `lg`; `TemplatesHaveNoRawTextTest` `BASELINE` emptied and read through a typed `baseline()`; deviation 4 (photo column above the stretched link, photos not clickable) **resolved** in rework round 1 by the per-slide links. `CLAUDE.md` now says `/` is the YAML route `home` (no controller).
- **Closed carry-forwards:** #1 F-m2 (main-menu focus wrap), #1 header search link, #1 long German labels at 320 px, #2 `BASELINE`.
- **#7/#8 reconciliation (done in #7, #8 landed first):** `venue_path()` generates `venue_show` (the `'a b'` test case dropped, the route's slug requirement rejects it); #8's "Alle Locations" → `path('listing')`; #8's similar-venue cards use `card_picture()` + `search/_card_facts` + `components/_badge` (the `similar.capacity` key and `.similar-card__town/__capacity` styles are gone), and `SimilarVenuesController`'s private CardPhoto → Picture mapping is gone (it passes `venues` only); #8's `_actions.html.twig` includes `favorites/_merken_button.html.twig` without `ignore_missing` and `VenueDetailControllerTest` asserts the `/merkliste/{number}` form; #8's `_actions.scss` now targets `.merken--detail .merken__button` (it styled a non-existent `.merken-button--detail`); with the Merken heart in the mobile bottom bar the bar overflowed its right padding by 16 px at 390 px (the primary button was `flex: 0 0 auto` through `.venue-actions .btn`), so below `lg` "Verfügbarkeit prüfen" now shrinks (`min-width: 0`, `padding-inline: var(--space-s)`) and wraps its label below 390 px; measured in Chrome: fits at 390 and 320 px, the footer clears the bar by 38 px (390) and 30 px (320). vnu: no errors on a detail page (the known #14 `#anfrage` warning). A real click on a card photo on the listing opens the detail page (200).
- **Carried forward:**
  - **#19/#20 — production must compress HTML.** Symfony `HttpCache` does not compress; gzip/brotli must come from Hostpoint (mod_deflate/brotli, `.htaccess`) or Cloudflare. Verify `Content-Encoding` on production `/hochzeitslocations`; without it the listing falls to ~78 Performance. #19 owns `Vary: Accept-Encoding` behind the reverse proxy and the long-cache header on `/assets`.
  - **Every lane, after rebasing onto #7:** `docker compose restart nginx` (`default.conf` is a single-file bind mount; `nginx -s reload` keeps the old inode). Lighthouse numbers from lanes without gzip are not comparable.
  - **#19:** a 404 past the last page is cached publicly for 10 min + SWR (decide `no-store` or a short TTL); the 4th query per page; reduced motion verified in code only (Chrome extension cannot emulate it); `aria-hidden` on the photo links hides their alt texts from screen readers (a11y pass: re-expose or accept); 5 tab stops per card.
  - **#26:** the listing intro text is one `content_block` line under the h1 in `search/_hero.html.twig`.
  - **#13:** `POST /merkliste/{number}` route, CSRF decision, `merkliste.js` on `[data-merken]`, the header heart, a `remove` card action; the filter-bar Merkliste button links to `home` until #13 adds `/merkliste`.
  - **#16:** SEO-1 meta description and `meta` block, `noindex,follow` for filter combinations, final canonical rules. **#17:** `heading` for `_hero`, landing links for `_related_searches`. **#12:** "weitere Filter +" is cut off at 390/320 px in the scrolling bar.
  - Pending human verification: touch swipe vs. tap on a card photo on a real phone at 390 px; screenshots and keyboard walkthrough (agent-verified).

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
- **For the CMS preview (#24):** `VenueDetailQuery` takes the venue by number and has an option to include unpublished venues; the page template does not assume it is reached through the public route (no `app.request` route checks inside sections).
- **Done when:**
  - application tests cover 200/301/404/410, all sections rendering with a full demo venue, and missing optional data (no rating, no rooms, no documents) hiding its section instead of leaving empty headings;
  - the screenshot comparison with `detail-page-top.jpg` and the live page's lower sections passes;
  - Lighthouse ≥ 95.

**Outcome (2026-10-30).** Done in commit `venue-detail-page`. Composer: `symfony/rate-limiter: 7.4.*` (v7.4.22; `config/reference.php` regenerated by Symfony). No migration, no table, no Twig function, no new Makefile target or console command. Catalogue domains: `venues` (`detail.*` tree) and `search` (`similar.{heading,all,label,capacity}`) extended, none added. Read `CLAUDE.md` `### Venues` ("Built in #8") and `### Search` before touching the page.

- **Routes:**
  - `venue_show` `GET /hochzeitslocation/{slug}` (localized, `['de' => …]`; slug requirement `App\Venues\Controller\VenueDetailController::SLUG_REQUIREMENT` = `[a-z0-9]+(?:-[a-z0-9]+)*`, so `Foo%20Bar` or `-kaputt-` never reach the controller) → `App\Venues\Controller\VenueDetailController`.
  - Status codes: current slug → 200; a `venue_slug_history` slug → 301 to the current one; a venue that is unpublished (status, or no `de` translation) → 410 (also through a history slug); unknown → 404. 404/410 use Symfony's default error pages (#16).
  - `venue_phone` `GET /hochzeitslocation/{slug}/telefon` → `App\Venues\Controller\VenuePhoneController`: the rate limiter runs first (→ 429 + numeric `Retry-After`), then the same 404/410/301 rules (301 to the current slug's `/telefon`). HTML page `templates/venues/phone.html.twig` by default (`noindex` meta; the no-JS fallback is a plain link), JSON `{"phone","display"}` for `Accept: application/json`. Headers `Cache-Control: private, no-store`, `X-Robots-Tag: noindex, nofollow`, `Vary: Accept`. **The page HTML never contains the number.**
- **Phone rate limiter** (`config/packages/rate_limiter.yaml`): `phone_reveal`, `sliding_window`, 20 per 10 minutes, keyed on the client IP, pool `cache.phone_reveal` (adapter `cache.app`, also in test: `VenuePhoneControllerTest::setUp()` clears it, because the test client resets array pools between requests). No lock (symfony/lock is not installed).
- **Cache headers:** 200 and 301 of `venue_show` send `Cache-Control: public, s-maxage=600, stale-while-revalidate=86400` (set in the controller, not `#[Cache]`); no session, no `Set-Cookie` (application-tested).
- **Read model `App\Venues\ReadModel\VenueDetailQuery`** (DBAL):
  - `resolveSlug(string $slug, string $locale): ?SlugMatch` (1 query; `SlugMatch` carries the id, the venue `number`, the current slug, whether it was a history slug, published or not, and the `name` for the phone page);
  - `detail(int $number, string $locale, bool $includeUnpublished = false): ?VenueDetail` (3 queries: venue + translations + facts, rooms, photos). Keyed by `venues.number`. Without the flag it applies the published predicate (status + default-locale translation); with it a draft is returned too. Still null when the venue has no translation in the locale or the default, since slug and summary are part of the page;
  - `phone(string $venueId): ?string` (1 query, published venues only, UUID guard).
  - View objects (all `App\Venues\ReadModel\`): `SlugMatch`, `VenueDetail` (`hasRatingSection()`, `hasFacts()`, `hasFaq()`, `hasContact()`; `lat`, `lng`, `street`, `zip`, `townName`, `photos`, `rooms`, `documents`, `reviews` …), `DetailFactGroup`, `DetailDistance`, `DetailDocument`, `DetailReview`, `DetailRoom`, `DetailSeating`. `App\Venues\ValueObject\PhoneNumber` formats E.164 for display (`044 123 45 67`, `0800 123 456`, other values as stored). Reviews are not filtered by locale (newest first); rooms/documents without any translation row are skipped; the read model translates the photo alt fallback.
- **Query count:** page = 4 queries (slug + detail) + 1 (similar venues) = **5** in the profiler, 9 ms query time; TTFB 30–43 ms warm (dev, demo ×1). p95 with demo ×10 is #19's.
- **CMS preview (#24):** call `detail($number, $locale, includeUnpublished: true)` and render `venues/detail/show.html.twig` from #24's own route. Every section is included `with_context = false` and receives only `venue`; no template checks `app.request` or `_route`. The only route references are generated links (`path('venue_phone')`, `url('venue_show')` for Teilen, `path('venue_show')` on similar cards).
- **"Ähnliche Locations" is a Search fragment.** `App\Search\Controller\SimilarVenuesController` (no route; `__invoke(Request, string $venueId)`, `SimilarVenuesQuery::forVenue()`, 12 cards, template `templates/search/_similar_venues.html.twig`) is embedded at the end of `show.html.twig` with `render(controller('App\\Search\\Controller\\SimilarVenuesController', {venueId: venue.id}))`.
  - Why this is acceptable under the module rules: templates compose fragments, and S2 splits the page between Venues (detail) and Search (similar venues). No PHP class in `App\Venues` depends on `App\Search` (phpat checks PHP only); the fragment's own dependencies (Search → Venues/Media) are allowed.
  - **For #16:** the fragment is a kernel sub-request, so request/response listeners run for it too; CSP-nonce and security-header listeners must act on the main request only and share its nonce.
  - **For #19:** may switch to `render_esi()` behind `HttpCache`. In debug an exception in the fragment is a 500; in prod the inline renderer drops the block.
- **Section order** (`show.html.twig`): hero carousel (`_hero`) + "Bilder ansehen" gallery `<dialog>` (`_gallery`) → sticky anchor nav (`_nav`) → sticky "Anfragen & Merken" card (`_actions`; below `lg` a fixed bottom bar) → head block with badge, "PLZ Ort", icon row, description (`_head`) → Bewertung (`_rating`) → fact lists (`_facts`) → Räume carousel (`_rooms`) with "Rauminfos"/"Eckdaten" dialogs (`_room_dialogs`) → Häufige Fragen (`_faq`) → `<section id="standort">` (`_location`: address, distances) → Kontakt with phone reveal (`_contact`) → Dokumente (`_documents`) → `<section id="anfrage">` → reviews with "Alle Kommentare" `<details>` (`_reviews`) → Ähnliche Locations + "Alle Locations". Every optional section (rating, facts, rooms, FAQ, contact, documents, reviews, gallery) is omitted entirely when its data is missing, with no empty heading (application-tested with a minimal venue).
- **Slots (final names):**
  - **#13:** `_actions.html.twig` (card and mobile bar) includes `favorites/_merken_button.html.twig` with `{number, name, label: 'detail.actions.merken'|trans({}, 'venues'), variant: 'detail'}`, `ignore_missing = true` until #7's file lands.
  - **#14:** `<section id="anfrage" class="venue-inquiry">{% block inquiry %}{% endblock %}</section>` in `show.html.twig`, after Dokumente and before the reviews. "Verfügbarkeit prüfen" links to `#anfrage`; the "E-Mail" contact link is a `mailto:` that #14 may point at `#anfrage`. The empty section is `display: none` (`.venue-inquiry:empty`).
  - **#15:** `{% block map %}{% endblock %}` at the end of `<section id="standort">` in `show.html.twig`; `venue.lat`, `venue.lng`, `venue.street`, `venue.zip`, `venue.townName` are available.
- **Assets:** styles `assets/styles/venues/_detail.scss` (+ `venues/detail/_{layout,hero,gallery,nav,actions,head,rating,facts,rooms,sections,reviews,print}.scss`, the print stylesheet DP-16 included) and `assets/styles/search/_similar-venues.scss`, two `@use` lines appended to `app.scss`. JS modules `assets/js/share.js` (Web Share, fallback popover with "Link kopieren"), `assets/js/print.js` (unhides Drucken, `window.print()`), `assets/js/phone-reveal.js` (fetches the JSON, swaps in the `tel:` link and focuses it). Icons `check`, `house` added (Lucide 1.57.0; sprite 38, `IconSpriteTest`, `LICENSES.md`). The page preloads the 600 font weight (fixed CLS 0.148 → 0). Below `lg`, `body.page-venue { padding-bottom: 4.5rem }` clears the bottom bar (reset in print).
- **Gates:** Lighthouse (mobile, prod-like per #7's P1 method) **99/100/100/100** on a rich (`alpstube-tannenberg`) and a sparse (`rebhaus-wiesental`) demo venue (LCP 1.8 s, CLS 0, TBT 0 ms); dev SEO 63 fails only `is-crawlable` (dev noindex). Sizes: `app.css` 8.1 KB gzip, all JS 2.6 KB gzip, rich page HTML 70 KB raw / 8.2 KB gzip. vnu: no errors on rich, sparse and `/telefon` (one warning: the empty `#anfrage` section has no heading until #14). Screenshot comparison at 1440/390 px matches `detail-page-top.jpg` in layout, order and colours; agent keyboard walkthrough: every control reachable with visible focus, no trap, gallery focus in/out correct. Tests: 606 (`VenueDetailQueryTest`, `VenueDetailControllerTest`, `VenuePhoneControllerTest`, `PhoneNumberTest`; shared fixtures in `tests/Support/Venues/DetailVenues.php`).
- **Deviations:** room dialogs are modal `<dialog>`s, not anchored popovers (D4); Rauminfos/Eckdaten follow the original (capacities under Rauminfos; no Grundriss link) (D3); generic `house`/`check` icons instead of per-term icons (D13); cache headers in the controller, not `#[Cache]` (P6); the limiter pool is `cache.app` in test too, cleared per test, instead of an array pool (P8); `SlugMatch` carries `number` and `name`; `tests/Support/Venues/DetailVenues.php` added; similar cards show capacity as `swiss_range(capacityMin ?? capacityMax, capacityMax)`; hero arrows move top right from `lg`; the share popover has no `aria-label` (vnu rejects it on a `div`); `{% block body_class %}` sets the class via a variable (raw-text scanner); no Merken button until #7 lands; our #1 header does not overlay the hero as the original's does. Rework round 1 made `detail()` take the venue number and the `includeUnpublished` flag (for #24) and moved the bottom-bar clearance from `main` to `body`.
- **Carried forward:**
  - **D19 reconciliation with #7** — #7 `listing-page` was not on main when #8 landed, so **#7's finisher does it**:
    1. #7's `venue_path(slug)` in `App\Search\Twig\ListingExtension` → `path('venue_show', {slug})` without the literal fallback, and "Alle Locations" in `_similar_venues.html.twig` `path('home')` → `path('listing')`;
    2. similar-venue cards in `templates/search/_similar_venues.html.twig` → `card_picture()` + `_card_facts` + `_badge`, and delete the private `CardPhoto → Picture` mapping in `SimilarVenuesController`;
    3. drop `ignore_missing` on the `favorites/_merken_button.html.twig` include in `_actions.html.twig` and add the `form[method="post"][action="/merkliste/{number}"]` assertion to `VenueDetailControllerTest`;
    4. merge `assets/icons/sprite.svg` / `IconSpriteTest` / `LICENSES.md` (both cycles' icons, count), `assets/styles/app.scss` (`@use` lines), `composer.json`/`composer.lock` (take main's, re-`composer require`), `CLAUDE.md`, the `search` catalogue;
    5. re-check the footer against the bottom bar at 390 px once the Merken button is in the bar (the 4.5rem clearance currently clears it by only 2 px).
  - **#24:** in a preview of an unpublished venue, "Telefonnummer anzeigen" (`phone()` requires published) and Teilen lead to public URLs that answer 410; #24 decides (hide them in the preview or accept).
  - **#14:** "Verfügbarkeit prüfen" goes nowhere until #14 fills `#anfrage`.
  - **#19/#20:** the phone limiter counts per client IP and no `trusted_proxies` are configured; behind a proxy every visitor shares one bucket. The limiter state is per server (`cache.app`). #19: p95 with demo ×10, the ESI option.
  - **#16:** robots `Disallow` for `/telefon`, canonical/OG/JSON-LD, 404/410 error templates.
  - Gallery `aria-live` covers the whole carousel body; it belongs to the shared `templates/components/_carousel.html.twig` (#1's carried-forward note), not changed here.
  - Pending human verification: real print preview (Cmd+P), Drucken and Teilen (Web Share sheet, clipboard fallback), no-JS behaviour in a real browser, the screenshot comparison.
  - Later language cycle: per-locale slugs for hreflang.

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
- **One write path for the CMS (S8):** the import dispatches `Venues` commands, and the handlers hold the logic: slug + history, town resolution, room summaries, the photo pipeline (stage → `VenuePhoto` → `copyVariants()`), document publishing and the cache purge. The CMS (#24/#25) reuses them with finer-grained commands, so no business rule lives in the import command itself. Name the purge interface `Venues\…\HttpCachePurger` (or Shared if research prefers), with `purgeVenue(int $number)` and `purgeListings()`, which #24–#26 call.
- **Done when:**
  - integration tests cover a fixture content folder for create, update, rename (301 works), photo change, invalid vocabulary and `--dry-run`;
  - running the import twice changes nothing.

**Outcome (2026-10-30).** Done in commit `venue-content-import`. No routes, no Twig functions, no catalogue domains, no new Makefile targets. Composer: `opis/json-schema: ^2.6` (pulls `opis/string`, `opis/uri`). Read `CLAUDE.md` `### Venues` ("Built in #9") and `docs/content-format.md` before touching content or a Venues write.

- **Schema:** migration `Version20261030140037`, with `down()`. `venues.content_key VARCHAR(100) NULL` (`uk_venues_content_key`, `chk_venues_content_key` kebab regex) is the content folder's name, immutable, NULL for demo venues. `venues.content_hash VARCHAR(64) NULL` (`chk_venues_content_hash`: only with a key, 64 lower-case hex) is SHA-256 over `ContentFormat::VERSION`, the `venue.yaml` as canonical JSON (keys sorted) and one `<relative path> <sha256>` line per listed file; raising `VERSION` makes the next import re-apply every venue. `rooms.content_key VARCHAR(100) NULL` (`uk_rooms_venue_id_content_key`, `chk_rooms_content_key`). Table `content_imports` (#9 owns it; entity `Venues\Entity\ContentImport`, getters only): one row per run that is not a dry run, with `id`, `source_path`, `only_key`, `pruned`, `forced`, `venues_seen/created/updated/unchanged/failed/unpublished`, `photos_added/removed`, `documents_added/removed` (INTEGER ≥ 0, `chk_content_imports_counts`), `errors` JSONB (`[{key, messages[]}]`, `chk_content_imports_errors`), `started_at`, `finished_at` (`chk_content_imports_finished_at`), `created_at`, `updated_at`, `chk_content_imports_only_key`. No column comments (DBAL would diff them).
- **Format:** `<CONTENT_ROOT>/venues/<key>/venue.yaml` plus `photos/`, `rooms/<room-key>/photos/`, `documents/`. **`docs/content-format.md` is the format reference** (layout, key rules, annotated fictional example, field reference, vocabulary, YAML pitfalls, file rules, import behaviour and options, editor setup, dropped legacy fields); `docs/content-format.schema.json` is the draft-07 schema, read at runtime from `ContentFormat::SCHEMA_FILE`. `ContentFormatDocsTest` validates the example, and `ContentSchemaVocabularyTest` pins the schema's enums to the PHP enums and `taxonomy_terms`, so a new term group or enum case has to change the schema too. Symbolic links are not allowed for the venue folder, `venue.yaml`, the sub-folders and every listed file (`docs/content-format.md`, §Files); a link no rule reads (a dotfile, an unlisted entry directly in the venue folder) is ignored and never opened.
- **`CONTENT_ROOT` and `MEDIA_ROOT`:** `CONTENT_ROOT` (`.env`: `<project>/content`, git-ignored, never committed; `.env.test`: `var/test-content`; parameter `app.content_root`) is the import's read-only input. `MEDIA_ROOT` stays #5's store of originals: the import copies each file to `MEDIA_ROOT/content/<key>/<sha256>.<ext>` (`ContentFormat::originalPath()`, content-addressed, `.jpeg` → `.jpg`), and that relative path is what `venue_photos.original_path` and `venue_documents.path` hold. After an import the site, `app:media:regenerate` and the variants under `public/media/<number>/` need only `MEDIA_ROOT`; `CONTENT_ROOT` is needed again only for the next import. Variants are staged in `app.import_staging_dir` (`var/import-staging`) and generated only for new photos.
- **Command:** `make sf c="app:venues:import [path] [--dry-run] [--only=key] [--prune] [--force]"`, the only new console command (`Venues\Console\ImportContentCommand`). `path` is a root containing `venues/` (default `CONTENT_ROOT`). `--dry-run` validates and reports, writes and records nothing. `--only=<key>` imports one folder. `--prune` unpublishes content venues whose folder is gone and nulls their hash (files kept; a folder that is present but invalid is not pruned; demo venues are never touched; not combinable with `--only`). `--force` applies every folder even when unchanged (photos are still not regenerated). `-v` also lists unchanged venues. Output: one line per created, updated or failed folder, a summary `Venues: N seen, x created, y updated, z unchanged, f failed, u unpublished. Photos: +a −r. Documents: +a −r.` (prefixed `Dry run:`), then `<key>: <message>` per failure. Exit 1 if any folder failed or for bad options (`--prune` with `--only`, an unknown or invalid `--only`, no `venues/` under the path). Run one import at a time.
- **Classes:** commands `Venues\Command\{ImportVenueContent, UnpublishMissingContent, RecordContentImport, RemoveVenueFiles, PurgeVenueCache, PurgeListingsCache}` with their handlers in `Venues\CommandHandler`; services `Venues\{ContentFormat, ContentSchema, ContentReader, SlugAllocator, VenueSlugs, TownResolver, RoomSummary, PhotoPipeline, DocumentPipeline, PublishedMedia}`; interface `Venues\HttpCachePurger` with the no-op `Venues\NullHttpCachePurger`; value objects `Venues\ValueObject\Content\{VenueContent, RoomContent, PhotoContent, DocumentContent, ReviewContent}`, `Venues\ValueObject\{ImportStatus, VenueImportOutcome, StagedPhoto}`; exceptions `Venues\Exception\{InvalidContent, UnresolvedTown}`. Added to existing classes: `TownRepository::findByZipAndName()`; `VenueRepository::{findOneByContentKey, isSlugTaken, contentVenuesMissingFrom}`; `MediaPublisher::{storeOriginal, removeOriginal, removePhoto, removeDocument}`; `Venue::{rename, recordContentHash, forgetContentHash}`, `Room::{moveTo, reshape}`, `VenuePhoto::moveTo`, `VenueDocument::moveTo`, `VenueTranslation::revise`. `Shared\Messenger\ResetClosedEntityManagerMiddleware` runs before `doctrine_transaction` on `command.bus`, so one failed command does not break the next dispatch in the same process. Test support: `tests/Support/Content/ContentFolder.php`, the `RunsContentImport` trait, and `Tests\Support\Http\RecordingHttpCachePurger` (`venues`, `listings`), the purger alias in the test env.
- **Behaviour:** one dispatch (one transaction) per folder in byte order; a failing folder fails alone. Everything is validated before the first write. An unchanged hash skips the venue, so a second run writes only its `content_imports` row. Rooms keep their row by key, photos by `(room key, original_path)`, documents by `path`, so a renamed or reordered file keeps its id, variants and URLs and changed bytes make a new photo. Terms, highlights, reviews and all translation sets are replaced. Positions move in two flushes (+10000) because the position uniques are not deferrable. Slugs: name slug, else `-<town-slug>`, else `-2`, `-3`… (≤ 160, cut at a hyphen); a slug is taken when another venue has it as a current or history slug; the old slug goes to `venue_slug_history`, a rename back deletes that row. Files of removed rows go through `RemoveVenueFiles` after the commit. `tests/Application/Venues/ImportedRenameRedirectTest.php` proves import, rename and the 301 through #8's route.
- **One write path (S8), what #21, #24, #25, #31 and #32 build on:** no business rule lives in `ImportContentCommand`; it only dispatches. The rules live in Venues services and handlers: slug + history in `VenueSlugs::assign()` (on `SlugAllocator`); ZIP + name (+ canton) in `TownResolver` (throws `UnresolvedTown`); room facts in `RoomSummary::apply()`; store original → stage variants → `VenuePhoto` → publish in `PhotoPipeline` (`openStaging`, `stage`, `add`, flush, `publish`, `discardStaging`; `StagedPhoto`); store → `VenueDocument` → publish in `DocumentPipeline` (`store`, `add`, flush, `publish`); clean-up of published files after a failure in `PublishedMedia::revert()`, one instance per write; file deletion after the commit in `RemoveVenueFiles`; the cache purge in `PurgeVenueCache` / `PurgeListingsCache`. `ImportVenueContentHandler` composes them and keeps only what is import-specific: matching content to existing rows, the two-flush position move, replacing owned sets, applying fields, outcome counts, JSON-pointer messages. Compose these; never duplicate their rules.
  - **#21 and #32** write content folders only, never the DB: `app:venues:import` loads them. They target `docs/content-format.md` (key recommendation `<ascii-name-slug>-<eventlokale-id>`, the values to clean, the dropped fields).
  - **#24:** `RenameVenue` uses `VenueSlugs::assign()`, `RelocateVenue` uses `TownResolver`, and every write handler dispatches `PurgeVenueCache` (+ `PurgeListingsCache` where listings change) with `DispatchAfterCurrentBusStamp`. A venue with a `content_key` is written only by the import until #24's guard (`cms_edited_at`, `--overwrite-cms-edits`) changes `ImportVenueContentHandler`.
  - **#25:** `AddVenuePhoto` = `PhotoPipeline` `openStaging` → `stage` → `add` → flush → `publish` (`discardStaging` in `finally`, `PublishedMedia::revert()` on failure); `AddVenueDocument` = `DocumentPipeline` `store` → `add` → flush → `publish`; room commands call `RoomSummary::apply()` with the venue's rooms; `RemoveVenuePhoto` / `RemoveVenueDocument` dispatch `RemoveVenueFiles` after the commit; reordering is #25's own.
  - **#31** adds fields in `docs/content-format.md`, the schema, `ContentReader`, `VenueContent` and `ImportVenueContentHandler`, so the hash, dry run and failure isolation apply to them unchanged; a change of an existing field's meaning raises `ContentFormat::VERSION`.
- **HTTP cache purge:** `Venues\HttpCachePurger` (`purgeVenue(int $number)`, `purgeListings()`) is never called directly. `ImportVenueContentHandler` dispatches `PurgeVenueCache($number)` for every created or updated venue, `UnpublishMissingContentHandler` one per unpublished venue, and `RecordContentImportHandler` one `PurgeListingsCache` when the run created, updated or unpublished anything, all with `DispatchAfterCurrentBusStamp`, so they run after the commit and are dropped when the dispatch fails. An implementation never throws (it logs), because a purge runs after the commit. The interface sits in `Venues`, not `Shared`, because mapping a number to its URLs needs Venues data. **#19** implements it and swaps the alias in `config/services.yaml`: `purgeVenue()` = the detail page under the current and every history slug per enabled locale plus its canton and landing pages; `purgeListings()` = the listing, the landing pages and the sitemap. Residual: a run interrupted before `RecordContentImport` purges the venues it committed but not the listings (bounded by `s-maxage`, S10).
- **Deviations:** own reset middleware instead of `doctrine_ping_connection` (that acts only for worker-consumed messages). Validation failures are returned outcomes; only the write phase throws. `VenueTranslation` is updated in place. `VARCHAR(64)` hash with a hex CHECK instead of `CHAR(64)`. `--force` added to the spec's options. Review `locale` is the text's language (`de|fr|it|en`, not required to be enabled). The import dispatches no fine-grained CMS commands (one transaction per venue); those are #24/#25's. `ContentSchema` rewrites two `opis` messages (`additionalProperties`, enum). Seating min-or-max is a schema rule. Duplicate photos are detected by content. A failed new venue also removes its `public/media/<number>/`. The failure clean-up is proven in two places: `VenueMediaPipelineTest` (`revert()`) and `ImportContentFailuresTest` (end to end, a test-only Doctrine `postFlush` listener makes `publishDocument()` fail after the photos were published).
- **Residual risk:** if the final `COMMIT` fails after the handler returned, that venue's new variants and PDFs stay under `public/media/<number>/` for ids that do not exist (harmless). Stored originals of a failed venue stay under `MEDIA_ROOT/content/<key>/` (content-addressed, reused by the next run).
- **Carried forward:**
  - **#20:** ship `docs/content-format.schema.json` (needed at runtime) if the import ever runs on the host, and mount `CONTENT_ROOT` and `MEDIA_ROOT`.
  - **#20:** decide on a `--prune` guard: on an empty `venues/` (an unmounted `/Volumes/…` root) it unpublishes every content venue, and the guard should also treat a dangling link in `venues/` as present (`ContentReader::keys()` reports it as a file today, so it counts as absent).
  - **#20/#21:** the early returns in `ImportVenueContentHandler` leave entities managed (memory only; an `em->clear()` before them fixes it). A PNG named `.jpg` is stored as `<sha>.jpg` (harmless).
  - ~~**#21:** needs a Docker volume for a `/Volumes/…` content root and decides on downloading room images.~~ Void: #21 reads only the Wix CSV, so there is no `/Volumes/…` root and there are no room images.
  - No import lock (run one at a time). Hashing reads every file on every run (~10–25 GB for the real set).

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

**Outcome (2026-10-30).** Done in commit `filter-region`. No migration, no table, no new route, no Composer change, no new Makefile target or console command. Catalogue domain `search` extended by 13 `filter.*` keys, none added. `_filter_bar`, `_overlay` and `SearchCriteria` are unedited. Read `CLAUDE.md` `### Geo` and `### Search` ("Built in #10") and `.claude/rules/frontend.md` (live count) before touching the overlay.

- **The overlay:** `templates/search/filter/_region.html.twig` (chips, trigger, overlay; #7's slot file) plus the new `templates/search/filter/_region_form.html.twig` (`criteria`, `selected`), one GET form `#filter-region-form` with two tabs. Its submit button sits in the shell's footer and joins through `form="filter-region-form"`. Kanton tab: client-side combobox over the 26 names in the HTML and #3's inline map (roving tabindex, one Tab stop, `role="button"`, `aria-pressed`, tooltip, one canton at a time). Ort/PLZ tab: debounced, aborting `/_search/places` combobox (six towns at most), radius slider, town pin and radius circle on the map. Styles `assets/styles/search/_filter-region.scss` (one `@use` in `app.scss`), tokens `--c-dropdown`, `--c-map-radius`. Lighthouse, prod-like, mobile: 99 / 100 / 100 / 100.
- **Without JS:** `<noscript><select name="canton">`, a text field that submits a ZIP as `place`, the native radius. Both panels show, one under the other. A no-JS visitor who re-submits on a town-UUID URL loses the town (accepted).
- **`assets/js/region-filter.js`:** tabs, both comboboxes, map, tooltip, radius, pin, "Filter löschen" and discard-on-close. Only the active tab's hidden field is enabled, so the two tabs never compete at submit; the Ort tab sets `inert` on the map. Text without a choice clears the value; four digits count as that ZIP. A keystroke closes the place list at once, so Enter can never take a row older than the text. Tooltip through `el.style.left/top`, never `setAttribute('style')` (CSP, #16).
- **`assets/js/live-count.js`, contract (final; #11 and #12 build on this):**

  ```html
  <form id="filter-x-form" method="get" action="{{ path('listing') }}"
        data-live-count data-live-count-url="{{ path('_search_count') }}">
      {{ include('search/filter/_hidden_state.html.twig', {criteria: criteria, except: [...]}, with_context = false) }}
      … the overlay's named controls, inside the form …
  </form>
  …
  <button type="submit" form="filter-x-form" class="btn btn--black"
          data-live-count-label
          data-count-zero="Keine Resultate"
          data-count-one="1 Resultat anzeigen"
          data-count-other="%n Resultate anzeigen"><span>Resultate anzeigen</span></button>
  ```

  - **Binding:** every `form[data-live-count]`. The label is found through `form.elements`, so the button may sit outside the form (the shell's footer) as long as it has `form="…"`. The `<span>`'s text is replaced.
  - **Recount triggers:** (1) `input` and `change` bubbling from inside the form, debounced 150 ms; (2) the overlay opening (`toggle` with `newState === 'open'` on `form.closest('[popover]')`), immediately.
  - **Programmatic changes:** hidden inputs fire nothing, so after setting a value dispatch `new Event('change', {bubbles: true})` on the control. That is the whole API; there is no global.
  - **Query:** `new URLSearchParams(new FormData(form))`, exactly what the form would submit. Disabled controls are skipped by the platform. A `formdata` listener removes empty values, repeated keys keep their non-empty values (`setting[]` is safe). **Verified in Chromium 156: this also holds on submit, so `?canton=&place=` reaches neither the fetch nor the URL.**
  - **Label:** `count === 0` → zero string, `1` → one string, else the other string with `%n` replaced by the Swiss-formatted number (`1'500`, straight apostrophe, matching `swiss_number`).
  - **Failure and races:** a new recount aborts the previous one. On a failed fetch (HTTP error or network) the label returns to its server-rendered text and the next change retries; the form still submits. Implementation detail: a query equal to the last successful one is not fetched again.
  - **Strings:** one ICU key, `filter.show_results`, rendered three times in the template (count 0, 1, and 2 with `formatted: '%n'`). Pass them through `_button`'s `attributes`.
  - **Loading:** one `<script type="module" src="{{ asset('js/live-count.js') }}">` in `listing.html.twig`'s `{% block scripts %}`, added by #10. #11 and #12 add nothing.
- **Twig:** `selected_place(criteria): ?App\Geo\ReadModel\ResolvedPlace` on `App\Search\Twig\ListingExtension`, whose constructor gained a third argument, `PlaceResolver`. Null without a place or for an unknown one. Chip: a town id shows "Stäfa", a ZIP "8712 Stäfa", unknown values the ZIP or "Ort".
- **Geo:** `App\Geo\ReadModel\PlaceResolver::resolve(string $place): ?ResolvedPlace` takes a town UUID or a 4-digit ZIP (a ZIP means its most populous town, the rule of `ListingSql`), memoized per value including misses, `kernel.reset`. `App\Geo\ReadModel\ResolvedPlace` has `name`, `zip`, `lat`, `lng`, `label()` ("8712 Stäfa").
- **`GET /_search/places`:** town entries now end in `"lat"` and `"lng"`; canton entries and headers are unchanged. `PlaceSuggestion` gained `lat`/`lng` (null for a canton). A map pin uses the affine fit in `region-filter.js` (2.29 SVG units per km, 96.4 % of towns land in their own canton).
- **Budget:** `AssetBudgetTest` holds the sum `overlay.js + live-count.js + region-filter.js` to 6 KB gzip (6,144 B). It stands at **5,980 B** (610 + 1,130 + 4,240, level 9), and at 6,037 B on the wire (nginx level 5), so **107 B of wire headroom** remain. Any further edit to those three files must find its bytes first. The source ships unminified, so its comments are the first place to look. All page JS also counts in the 15 KB test.
- **Deviations from the plan:**
  - `_region_form` wraps map and credit in `.region-filter__side`, so the credit stays under the map at 1440×900.
  - Grid rows are `1fr repeat(5, auto) 1fr` with the side column spanning all rows, not `align-content: center`.
  - Below `md` the input is `17.5rem`, not the original's 195 px, or "Ort oder PLZ eingeben" clips.
  - In this overlay text inputs show focus as a doubled underline, not #1's box, scoped to `.region-filter`.
  - `#cantons path { outline: none }` is unconditional, because Chromium drew a dark outline on a clicked path, partly under its neighbours. Keyboard focus shows as the path's stroke.
  - `autocomplete="off"` on the form, because Chromium can restore JS-set hidden values on history navigation.
  - Accepted against the original: full-screen instead of the sheet below 768 px, no ghost suggestion, no × in the input, no "Wird aktualisiert" state, computed tooltip positions, the "8712 Stäfa" list format, no auto-focus on open with a coarse pointer, and no third portrait circle at 390 px (it would mean editing #1's shell).
- **Accepted limits:** a typed ZIP counts and submits but shows no pin until the page reloads. "zue" also lists Zug (the fold reduces it to "zu"). Esc closes a shown list first, otherwise the overlay, which discards. A quick Enter on unfinished text shows every venue: typing "Stäfa" and Enter in one motion at ≤ 180 ms per key lands without a place filter, while a slower pace lets the fresh row take the Enter.
- **Carried forward:**
  - **#11, #12:** the seam gives you the count, not the overlay's behaviour. The stay-open "Filter löschen" and discard-on-close live in `region-filter.js`, not in the shared seam. Without own JS the reset link navigates and applies at once (it works, but differs from the original), and pending values survive closing. "Add nothing" above and "add no script tag" in `CLAUDE.md` are about `live-count.js` only.
  - **#11:** needs its own module for FI-3's bubbles anyway. It stays outside the 6 KB sum, which names three files, and counts only in the 15 KB test. #12 can decide.
  - **#11, #12, markup traps:** `.filter-bar` text must not contain "Karte" (`ListingControllerTest::test_filter_bar_has_three_triggers_and_overlays`; the overlays render inside the bar, visible text and `<noscript>` count, `aria-label` does not). `[hidden]` has no global CSS rule, so any class that sets `display` needs its own `&[hidden] { display: none; }`. Layout rules that set `display` on an overlay belong under `:popover-open`, or the closed popover shows. `#filter-x .overlay__footer a` must stay the reset link, so the submit button is a `<button>`. A no-JS `<select>` inside `<noscript>` needs an explicit `color` and `background`. `AssetBudgetTest` greps the raw JS, comments included, for the words `export ` and `console.`.
  - **#16, #19:** a submitted overlay does not produce what `toQuery()` writes, on five counts: `radius=5`, the default, stays in the URL after a town is chosen; hidden state comes first (`guests=80&…&canton=zh`), not in `toQuery()`'s fixed key order; repeated params are `setting[]`, where `http_build_query` gives `setting[0]`; no-JS submits add `canton=&place=&radius=5`; JS with nothing chosen lands on a bare `/hochzeitslocations?`. All five parse to identical criteria, so behaviour is correct and the URL code stays as it is. #16 decides the `canonical` tag; for #19 the cost is one page under several cache keys, hit rate only.
  - **#21:** the owner decided that the Ort/PLZ tab is hidden until venues have towns, and #21 hides it. The mechanism, from #21's research (D5):
    - `Search\ReadModel\FilterDataQuery` runs one `SELECT EXISTS` on `ListingSql`'s published predicate, memoized per request, with `place` meaning at least one published venue with a town. `filter_has_data('place')` on `ListingExtension` exposes it, because `_filter_bar` passes only `criteria` and `part`.
    - When that is false and `criteria.place` is null, `_region_form` gets a modifier class. Two CSS rules hide the tablist and the Ort panel in both the JS and the no-JS view, and Twig adds `disabled` to the Ort input and the slider, so a no-JS submit sends no empty `place=`.
    - **Keep the markup and change no JS.** `region-filter.js` reads the place input, list, slider and `<output>` unguarded and unhides the tablist itself, so removing them breaks the module, null guards do not fit the 107 B, and the hiding has to be `display: none` from the modifier, not `hidden`.
    - A bookmarked `?place=` still filters and its chip still renders; the tab shows for that request. The first venue with a town brings the tab back with no flag and no deploy (CMS writes purge the listing). Demo data has towns, so #10's tests keep working; #21 adds a test on town-less venues.
  - **Owner options, none blocks:**
    - the selected canton is dark; the original's is white (one declaration on `[aria-pressed='true']`);
    - the place list flickers at ordinary typing pace (F-m4: on "Winterthur", at 250–300 ms per key it shows about 7 times in 10 keys for under 100 ms each; nothing moves). The other route keeps the list, drops the highlight on `input` and lets Enter without a highlight submit, which costs bytes from the 107;
    - five details at 390 px differ from the original (F-m5): tabs centred, not left at x 16; Ort input 280 px centred, not full width; "Umkreis +5 km" and slider on two rows, not one; map directly under the input, not centred in the free space; button 358 px at x 16, not 326 px at x 32. One small styling pass if wanted;
    - if the Ort/PLZ tab returns, whether an exact name typed in one motion plus Enter should filter rather than show everything.
  - **Pending human:** Firefox and Safari (`formdata`, Esc, `inert`, `popover`; every check ran in Chromium 156 or real Chrome), a real phone at 390 px (small cantons, soft keyboard, `scrollIntoView` with it up), a screen reader (including `aria-expanded` toggling per keystroke), and a human look at `var/shots/region-*.png` beside `docs/reference/`.

### 11. `filter-guests`

- **Overlay** per FI-3: number input; bubbles as buttons, absolutely positioned like the screenshot, with sizes scaled by value, that fill the input.
- **Live count** via `live-count.js`; chip label "120 Gäste".
- **Mobile:** research checks the original at 390 px.
- **Done when:**
  - an application test covers the guests filter;
  - the screenshot comparison passes;
  - keyboard-only works.

**Outcome (2026-10-30).** Done in commit `filter-guests`. No migration, no table, no new route, no query, no Composer change, no new catalogue domain, no Makefile target. `SearchCriteria`, `_filter_bar`, `_overlay`, `overlay.js`, `live-count.js`, `region-filter.js` and `overlay-form.js` are unedited; the filter itself (`guests`, `capacity_max ≥ g AND (capacity_min IS NULL OR capacity_min ≤ g)`) is #6's.

- **Slot:** `templates/search/filter/_guests.html.twig` (chip, trigger "Anzahl Gäste", overlay titled `filter.guests_title` "Wieviele Gäste erwartest du?"; footer = the live-count submit with `form="filter-guests-form"` plus `a.overlay__clear[data-overlay-clear]` "Filter löschen") and `templates/search/filter/_guests_form.html.twig` (`criteria`) with **two sibling GET forms** to `listing`:
  - `#filter-guests-form`: the number input `#filter-guests-input` (`name=guests`, `type=number`, `min=1`, `max` = `SearchCriteria::MAX_GUESTS`, `step=1`, `inputmode=numeric`), `data-live-count data-live-count-url="{{ path('_search_count') }}"` and `data-overlay-form`.
  - `form.guests-bubbles[data-guests-bubbles]` (`aria-label` `filter.guests_picks` "Gästezahl wählen"): nine `<button type="submit" name="guests" value="20|30|50|80|100|150|200|350|500">`, ascending (DOM order = stacking and Tab order), with server-rendered `aria-pressed`.
  - Each form has its own `_hidden_state` with `except: ['guests']`, so both keep every other filter, #33's wedding filters included.
  - **Why two forms:** a form's default button is its first submit button, so a bubble inside the input's form would be what Enter submits. Rule added to `.claude/rules/frontend.md` (Overlays): submit buttons beside an overlay's input go in a sibling form.
- **`assets/js/guests-filter.js`** (683 B gzip, one `<script type="module">` in `templates/search/listing.html.twig` after `overlay-form.js`): a bubble click fills the input instead of submitting, a second press empties it, then a bubbling `change` (so `live-count.js` recounts). `sync()` derives `aria-pressed` from the input on the form's `input`, `change` and `reset` (`setTimeout`, because `reset` fires before the values are restored), so `overlay-form.js`'s clear and discard-on-close work unchanged. Opening focuses the input unless `(pointer: coarse)`. Without JS a bubble submits its number with the other filters.
- **Chip:** `chip.guests` in `translations/search+intl-icu.de.yaml` is now an ICU plural on `count` with `guests` = `count|swiss_number` ("1 Gast", "1'500 Gäste"); new keys `filter.guests_title`, `filter.guests_picks`.
- **Styles:** `assets/styles/search/_filter-guests.scss` (one `@use` in `app.scss`), layout scoped to `.overlay--guests:popover-open`. One Sass map `value: (left, top, diameter, font)` in px of the 1440 px original, emitted as percent of a `666 / 583` stage with `container-type: inline-size` (font `max(.9375rem, …cqi)`), the same map at every width; classes `.guests-bubble--<n>`, never a `style` attribute. Hover `--c-accent-soft`, pressed `--c-ink` with `z-index: 1`, `:focus-visible` z 2. Two `ContrastTokensTest` rows: `ink-inverse` on `ink` and on `accent-soft`.
- **Tests:** `tests/Application/Search/GuestsFilterTest.php` (14 methods, 19 cases): filter semantics incl. `capacity_min` NULL, count endpoint = listing, chip plural and remove link, invalid values dropped, live-count contract, nine bubbles in order, pressed state, `test_enter_cannot_reach_a_bubble` (the two-form guard), hidden state of both forms, `test_both_forms_carry_the_wedding_filters` (#33's carry-forward: `ceremony[]`, `amenity[]`, `usage[]`, `price[]`, `curfew` in both forms), a no-JS bubble submit.
- **Budgets:** page JS (`AssetBudgetTest`) 13,642 of 15,360 B gzip (1,718 B headroom); `overlay.js + live-count.js + region-filter.js` 5,980 of 6,144 B, unchanged; `ListingHtmlBudgetTest` worst case 51,313 B gzip of 61,440 (was 49,392 after #33); still 7 queries per listing page. Lighthouse prod-like mobile 99 / 100 / 100 / 100; vnu clean apart from #10's known warning; headless Chromium checks at 320/390/768/1024/1440: no overflow, 9/9 bubbles hit-testable, JS, no-JS and keyboard flows pass.
- **Deviations from the original (plan, recorded):** (1) bubble numbers `--c-ink-inverse` (`#121212`), not white, and the selected bubble is white with dark text, not pink text (AA); (2) a second press clears; (3) no × in the input (as #10); (4) one position map at every width, so positions at 390 px are a few px off and big bubbles' text is smaller; (5) full-screen below 768 px instead of a sheet, × with its circle (the shell's); (6) real buttons instead of labels; (7) **review F-m1:** at 1440 px the bubble stage is 606 px wide, not the original's 666 px (bubbles about 9 % smaller, the cluster about 44 px further left), caused by plan Step 4.1's symmetric xl padding (`_filter-guests.scss:56-58`); cosmetic, left as is. **From the plan:** the input's `:focus-visible` doubles the underline (as #10's place input) instead of the boxed outline; `sass:list` (`list.nth`) instead of `sass:map`; the wedding hidden-state test beyond the plan's list.
- **Carried forward:**
  - **#17 (`seo-landing-pages`):** the `listing_form()` conversion must cover **both** guests forms: `#filter-guests-form` (action, hidden state, count URL from `listing_form().count`) and `form.guests-bubbles` (action and hidden state; it has no count), or a bubble on a landing page drops the landing's facets without JS.
  - **#15 (`venue-map`):** page JS headroom after #11 is 1,718 B; if `venue-map.js` (≤ 500 B) lands second, re-run `AssetBudgetTest`.
  - Low confidence: on a phone the hover colour may stay on a bubble after a second tap until the next tap (no `(hover: hover)` convention yet).
  - **Owner options:** white numbers on the large bubbles; a × in the input.
  - **Pending human:** Firefox and Safari (`cqi` with `aspect-ratio`, `formdata`, `form.reset()` on close, a no-JS bubble submit), a real phone at 390 px (tap accuracy, numeric keyboard, soft keyboard over the sticky footer), a screen reader (nine `aria-pressed` buttons in the labelled form), a look at `var/shots/guests-*` beside `docs/reference/filter-guests.jpg`.

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

**Outcome (2026-10-30).** Done in commit `filter-more`, rebased onto #21. No migration, no table, no new route, no Composer change, no new catalogue domain, no new Makefile target. `_filter_bar`, `_overlay`, `SearchCriteria`, `overlay.js`, `live-count.js` and `region-filter.js` are unedited.

- **Slot:** `templates/search/filter/_more.html.twig` holds one `params` array (`q setting access building infra furniture tech surroundings rooms area height`) that drives the pill's N, its × link, the reset link and `_hidden_state`. `part: 'chips'` renders nothing; `part: 'trigger'` renders the trigger "weitere Filter +" or the pill, plus the overlay. Partials: `_more_form.html.twig` (the GET form `#filter-more-form`), `_more_tiles.html.twig` (round tiles: Lage, Erreichbarkeit), `_more_terms.html.twig` (chips with the hiding rule inside; `collapse` puts the codes after the first 11 in `<details>`, open when the group is active), `_more_select.html.twig` (one Raumangebot select). A `.filter-more__group` renders only with content, so a rule never stands alone.
- **Pill "weitere Filter (N)":** `.chip--more`, a `button[popovertarget]` plus a clear link. **N counts groups (params), not values**, as on the original: five values in three groups read "(3)". A single value is removed in the overlay, not from the bar. That is what research found on the original, so the per-value chips of the section above are not built.
- **`App\Search\ReadModel\FilterDataQuery`** (`kernel.reset`; shared with #21, which added `place`): a filter has data when at least one published venue, by `ListingSql`'s predicate, carries it; nothing is faceted. `has(string $filter): bool` answers `place`, `access.station|highway|airport` (a venue within `Access::maxDistanceKm()`, not merely a non-NULL distance), `rooms`, `area`, `height`; an unknown key throws `\InvalidArgumentException`. `terms(TermGroup $group, array $active = []): list<string>` gives the group's codes with data or in `$active`, in vocabulary order (`primary_position NULLS LAST`, then `position`); a group outside `SearchCriteria::PARAMS` gives `[]`. One flags statement for all keys and one terms statement for all groups, each memoized per request. `flagsStatement()` and `termsStatement()` are public for `ListingQueryPerformanceTest`.
- **Twig** on `Search\Twig\ListingExtension` (fourth constructor argument): `filter_has_data(key)` and `filter_terms(group, criteria)`, which passes the group's active codes. `ListingSql::accessColumn(Access)` is now the only column map.
- **Per-value hiding:** a control shows when it has data or is active, because `_hidden_state` skips the slot's params and a hidden active value would be lost on the next submit. Per value for terms and `access`, per control for `rooms`, `area`, `height`. Only "Name eingeben" always renders. A filter returns by itself once a published venue carries its data: no flag, no deploy step. **After #21 alone, production shows only "Name eingeben"**; the rest appears with #32's data.
- **`assets/js/overlay-form.js`** (shared, loaded once by `listing.html.twig`; 585 B gzip): a form in a popover gets `data-overlay-form`, its reset link `data-overlay-clear`. The link then empties the form (checkboxes, text, selects, never hidden inputs), stays open and dispatches `change` on the form so `live-count.js` recounts; closing the overlay runs `form.reset()`, so what was not applied is discarded. Without JS the link navigates. Contract in `.claude/rules/frontend.md`.
- **Also changed:** `_text_input` reads `label_hidden ?? true`, because Twig's `default` treats `false` as empty (#12 is the first caller that passes `false`). `translations/search+intl-icu.de.yaml`: `filter.more_title` changed, 17 `filter.more_*` keys added, `chip.q|rooms|area|height` removed. Token `--c-overlay-more-tile` (`#524f4b`); styles `assets/styles/search/_filter-more.scss`.
- **Budgets, measured after the rebase:** `overlay-form.js` 585 B gzip; page JS 9,527 of 15,360 B (with `merkliste.js` at its 3,432 B: 12,959 B); `overlay.js + live-count.js + region-filter.js` 5,980 of 6,144 B; CSS `app` 13,342 of 25,600 B gzip; HTML worst case 48,603 B gzip of 61,440 (741,173 raw; seeded text moves it by a few hundred bytes); live demo page 53,690 B gzip -9 (581,011 raw). **7 queries per listing page:** cards, photos, count, known terms, top cantons, filter flags, filter terms; `selected_place()` adds 1 with a place, and `place` joins the flags statement, so #21 adds none. `MoreFilterTest` pins it. At ×10 venues, flags 0.18 ms and terms 3.95 ms against the 20 ms budget.
- **Tests:** `tests/Application/Search/MoreFilterTest.php` (each group, hiding, pill, 7-query pin), `tests/Integration/Search/FilterDataQueryTest.php` (thresholds, published rule, memo, order, active union, `place`), `ListingHtmlBudgetTest` now renders a full overlay (72 checkboxes, 21 options), `ContrastTokensTest` rows for the tile. `make test` after the rebase: 1,545 tests, 19,843 assertions, plus 7 admin tests. Lighthouse prod-like mobile was 99 / 100 / 100 / 100 before the rebase and was not repeated; every browser check ran in Chromium.
- **Deviations from the plan and FI-4:** round tiles for Erreichbarkeit (the original has them); no mobile short title (the shell escapes one `title`); 11 building chips at 390 px where the original shows 5; no sticky title bar, the × is `position: fixed`; "Alle Gebäudearten" sits under the chips, not in the grid's 12th cell (no JS); tile colour darkened for AA; Erreichbarkeit is multi-select (#6's `access[]`), the original's is single-select; the pill stays pink like `.chip`; "Am Fluss" stays hidden until a venue carries `river` (#32's call, S14 has no keyword).
- **Carried forward:**
  - **#33:** append to `params` once and insert after the Name group at the comment in `_more_form.html.twig`. `_more_terms` calls `filter_terms()` itself, so the `amenity` const list needs one optional `codes` param, and it must keep active values or an active `amenity` outside the list is counted by the pill with no control. Add the wedding group to the `[location, buildings, properties, rooms]` loop, not as a bare group, or an empty one leaves a stray rule. `FilterDataQuery` takes `price` and `curfew` as keys; `terms()` follows `SearchCriteria::PARAMS`.
  - **#11:** reuse `overlay-form.js`, no second script tag. Check it against the bubbles: clear fires `change` on the form and `form.reset()` fires nothing, so no event reaches the control; a `radio` or `range` control would get `value = ''`.
  - **#13:** page JS sum 12,959 of 15,360 B with today's `merkliste.js`. **#19:** the listing runs 7 queries.
  - **Later, low value:** a new `setting` code needs its icon in `setting_icons` in the same change, or every listing page answers 500. Option-level hiding in the Raumangebot selects: "≥ 6 Räume" with no such venue gives "Keine Resultate" once sparse CMS data exists.
  - **Owner options, none blocks:** dark `#2c2c2c` pills like the original, two declarations in `_chip.scss` (`--c-surface-footer`, `--c-ink`, already an AA pair), all three chip kinds change. F-m1: the pill's pink padding does not react to clicks (`.chip__open` is 84 × 22 in a 150 × 24 pill); fix by moving the padding onto the two controls, no template change. F-m2: the white focus ring on pink passes at 3.49:1 (`--c-accent-hover`) and 3.01:1 (`--c-accent`) but has no `ContrastTokensTest` row.
  - **Pending human:** Firefox, Safari, a real phone at 390 px, a screen reader on the fieldsets and the pill's two controls, a human look at the screenshots beside `docs/reference/filter-more.jpg`.

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

**Outcome (2026-10-30).** Done in commit `merkliste`. No migration, no table: the list lives in the browser. Composer `symfony/security-csrf: 7.4.*` (also #14's; pulls `security-core`, `password-hasher`). No console command, no Makefile target. Read `CLAUDE.md` `### Favorites` before touching the Merkliste.

- **Cookie contract:** name `merkliste`, host-only, `Path=/`. Logical value: venue numbers (`venues.number`), comma-separated, in saved order (oldest first; a re-add moves to the end). Wire format: percent-encoded comma, `12%2C34%2C56`, on both sides (a raw comma reads the same). **A trailing `%2C` (`12%2C34%2C`) marks a list written by JS and not yet re-issued by the server;** only JS writes it, both readers ignore it, and `POST /merkliste` and `GET /merkliste` remove it. A marked value with no valid entry sends no request and is never rewritten. `Max-Age` `34560000` (400 days), renewed on every write; an empty list deletes the cookie. `SameSite=Lax`, `Secure` on HTTPS, not `HttpOnly`. At most 100 entries; at 100 an add is refused, nothing is evicted.
- **Codec:** `App\Favorites\ValueObject\MerklisteCookie` (`NAME`, `MAX`, `MAX_AGE_SECONDS`; `fromRequest()`, `parse()`, `toggled()`, `without()`, `only()`, `value()`, `toCookie()` …). A token is `^[1-9]\d{0,9}$` and ≤ 2147483647; garbage dropped, duplicates keep their first position. `assets/js/merkliste.js` repeats the rule and the format: change both together.
- **Routes** (all `private, no-store`, `X-Robots-Tag: noindex, nofollow`, no session, no database except the page):
  - `merkliste_toggle` `POST /merkliste/{number}` (`App\Favorites\Controller\MerklisteToggleController`): the no-JS toggle, 303 to the same-origin `Referer`, else `/merkliste`. `intent=remove` (sent by `favorites/_remove_button.html.twig`) only ever removes. No command or handler: nothing is persisted and a handler cannot set a response cookie.
  - `merkliste_sync` `POST /merkliste` (`MerklisteSyncController`): re-issues the request's list as an HTTP cookie (204, no `Location`; `Set-Cookie` only when the value needs a rewrite), so the server is the last writer and WebKit's 7-day cap on `document.cookie` does not apply. It never flips, never deletes. A recorded S5 deviation (one more method on an S5 path).
  - `merkliste` `GET /merkliste` (`MerklisteController`): the only GET that reads the cookie; cards through `App\Search\ReadModel\ListingQuery::cardsByNumbers(list<int>, string $locale): list<ListingCard>` (published only, saved order, two queries, none for an empty list; `LEFT JOIN towns`, so a venue without town or canton is a card) and #7's `search/_card.html.twig` with `action: 'remove'`; prunes stale numbers with a `Set-Cookie`; empty state, count and full notice always rendered, `hidden` when not needed.
- **CSRF:** stateless `SameOriginCsrfTokenManager`, token id `submit` (as #14), field `_token`, constant value `csrf-token`, so cached HTML is the same for everyone; the check is `Sec-Fetch-Site`, else `Origin`/`Referer`. Required although the cookie is `SameSite=Lax`: a cross-site POST's `Set-Cookie` would still replace the list. A failed check is 403 and no cookie.
- **Cached pages never read the cookie:** no `Set-Cookie`, no `Vary: Cookie`, byte-identical HTML with and without it (`MerklisteCookieDoesNotVaryTest`, clock frozen with `mockTime()`). The header heart link carries `data-sync-token="csrf-token"`, the same constant on every render.
- **`assets/js/merkliste.js`** (one `<script>` line in `templates/base.html.twig`, every page; about 3.4 KB gzip): sets `aria-pressed` on every `[data-merken]` and the header count, takes over the Merken and remove forms (toast MK-4 "… wurde deiner Merkliste hinzugefügt." with a link, plus "entfernt" and "voll"), writes the marked cookie, and syncs 3 s after a burst, on a load that finds the marker, and with `keepalive` on hide or `pagehide`. No marker, no request. A per-tab journal re-asserts its own writes against a stale answer and ends at a clean landing or when the page is left. Removal guard 450 ms plus `event.repeat`; refresh on bfcache restore, `visibilitychange` and `focus` (`/merkliste` reloads when the list differs). The data-attribute contract is in `CLAUDE.md`.
- **Templates and strings:** `favorites/_merken_button.html.twig` (parameters unchanged, now with route and `_token`), `favorites/_remove_button.html.twig`, `favorites/merkliste.html.twig`; the header and filter bar link `path('merkliste')`. Styles `assets/styles/favorites/_merken.scss` (pressed heart), `_merkliste.scss`. Catalogue `favorites` extended (`remove.*`, `merkliste.*`, `toast.*`, `header.count_label`).
- **Tests:** `tests/Unit/Favorites/ValueObject/MerklisteCookieTest`, `tests/Unit/Favorites/MerklisteScriptTest` (only the constants `merkliste.js` shares with the codec, and its timing), `tests/Application/Favorites/{MerklisteToggleControllerTest, MerklisteSyncControllerTest, MerklistePageTest, MerklisteCookieDoesNotVaryTest}`, `cardsByNumbers` cases in `ListingQueryTest`, and `admin-ui/tests/public/merkliste.test.js`: the behaviour of `merkliste.js` on #22's Vitest + jsdom (no config of its own; `admin-ui/` is only the tool location). `location.reload()` and real bfcache stay browser-only.
- **#18 (Datenschutz facts):** one first-party cookie `merkliste`, no `localStorage`; functional, no consent banner (S7). Content: public venue numbers only, up to 100, no personal identifier. Read only by `/merkliste`, `POST /merkliste/{number}` and `POST /merkliste` (which only sends the same list back); not stored on the server, not passed to venues or third parties. The visitor controls it on `/merkliste` or by clearing cookies; per browser and device. **Lifetime: up to 400 days from the last change**, renewed on each change, deleted when emptied. Normally the page writes the cookie and a few seconds after each change (at the latest on leaving the page or on the next page view) the server re-issues it for 400 days. If that re-issue never ran (offline or request blocked, and no return visit): browsers without that cap keep 400 days (measured in Chrome; Firefox not checked); Safari and every browser on iOS keep a list **7 days from the last change**, because WebKit caps cookies written by script, and **24 hours** on a landing page reached through a link-decorated URL (a click id from a domain WebKit classifies as a tracker, so paid traffic is the likely case). The source is WebKit's documentation; nothing was reproduced in WebKit. The legal text says "up to 400 days", not "400 days".
- **Known limits:** without JS a cached page cannot show the saved state (the button toggles blind; `/merkliste` is the no-JS source of truth). A removal in one tab during another tab's sync can return, an old entry as well as a new one, and, with no time bound, when the first tab's sync got no landing (a failed POST, or a tab the system suspended): entries are favoured over removals, as a returned entry is one click to fix and a lost one is silent. One tab: a removal on the next page while the previous page's keepalive is still out returns; that needs a POST slower than about a second. A tab that writes during another tab's flight and then closes or leaves within that round trip can lose its write, as can a keepalive answer slower than the writing tab's delay plus its flight. Without JS, a double-click whose second click lands after the reload removes the next card.
- **Deviations:** `POST /merkliste` and the marker/journal were added in rework (review F-M3, Safari's cap), not in the first plan; one `<script>` line in `base.html.twig`; hotspot edits in `_filter_bar` and `_header` (links); extra toasts "entfernt" and "voll" beyond MK-4; a `<meta name="description">` on `/merkliste`.
- **Carried forward:**
  - #16: the CSP needs `connect-src 'self'` (else the sync fails silently and every list stays at 7 days on Safari and iOS); its finisher walks `merkliste.js` under the CSP. `Disallow: /merkliste` in robots covers the page and the POST. Keep a same-origin `Referer` (`strict-origin-when-cross-origin`).
  - #19: keep `POST /merkliste` on the page's host (a CNAME or API host could bring WebKit's cap back), pass `Set-Cookie` through, never cache it. A no-JS toggle's 303 invalidates its `Location` in `HttpCache`. Missing `trusted_proxies` now costs `Secure` on every saved list and breaks the CSRF `Origin` fallback.
  - #20: confirm that Sentry does not capture request cookies (`send_default_pii` off).
  - #10: the Vitest harness fits `live-count.js` and `region-filter.js` too.
  - **Pending human:** Safari on macOS, the premise (`Expires` ≤ 7 days right after a heart) and the fix (about 400 days and no trailing `%2C` a few seconds later): **Safari is not shown fixed**; iOS Safari and one other iOS browser, the same checks; keepalive on leaving there; iPhone double-tap on "Entfernen"; real tab and window switches in Chrome; Firefox (keepalive, cookie cap), not run at all.
  - **Owner options** (reversible defaults): label "Merken" when saved vs the original's "Gemerkt"; refuse at 100 vs evict; the extra toasts; oldest first vs newest first; `/merkliste` pruning stale entries.

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
- **For the CRM (#29):** after the inquiry is stored, the handler dispatches nothing CRM-specific. It publishes a Symfony event `Inquiries\…\InquirySubmitted` (inquiry id) after the transaction commits, so #29 can subscribe without Inquiries knowing the CRM. The purge must also remove what #29 derived (#29 subscribes to an `InquiryPurged` event, or its FK cascades; research picks).
- **Done when:**
  - application tests cover success, 422 with values kept, honeypot, too-fast submission and the rate limit;
  - Mailpit receives both mails;
  - the override is honoured.

**Outcome (2026-10-30).** Done in commit `inquiry-form`. Module `App\Inquiries`, no JS, one migration.

- **Table:** `inquiries`, migration `Version20261030152935` (hand-written, `down()` drops the table), entity `App\Inquiries\Entity\Inquiry`, mapping `orm.mappings.Inquiries`. `venue_id` → `venues(id) ON DELETE SET NULL`, so a row outlives its venue, with a snapshot in `venue_number`, `venue_name`, `venue_slug`, `recipient_email` (the venue's address, always) and `delivered_to` (the real envelope recipient, which differs under the override). Also `locale`, `occasion` (`ValueObject\Occasion`, 5 codes), `guest_count` (1–5000), `event_date`, `message`, `first_name`, `last_name`, `email`, `phone`, `fingerprint`, `venue_mail_status`, `guest_mail_status` (`ValueObject\MailStatus`: `pending|sent|failed|skipped`), `venue_mail_sent_at`. No IP address or user agent. Indexes on `venue_id`, `created_at` and `(fingerprint, created_at)`. `tests/Integration/Inquiries/InquirySchemaMigrationTest.php` has one rejection case per constraint.
- **Routes:** `inquiry_submit` `POST /hochzeitslocation/{slug}/anfrage` (`Inquiries\Controller\SubmitInquiryController`, localized). Unknown slug 404, unpublished 410, no inquiry address 404, a history slug is accepted. Every response is `private, no-store`, `X-Robots-Tag: noindex, nofollow`, no cookie. The confirmation is a 303 to `venue_show?anfrage=gesendet` (signed with `UriSigner`, constant per venue) + `#anfrage`.
- **Form:** `{{ inquiry_form(venue, form = null) }}` (`Inquiries\Twig\InquiryFormExtension`) fills #8's `{% block inquiry %}`, without a query or session, so the detail page stays public and cached: nothing without an inquiry address (`.venue-inquiry:empty` hides the section), the confirmation on the signed flag, else the form. `Form\InquiryFormFactory::create(VenueDetail)` is the one place that builds it; `Form\InquiryType`, `Form\InquiryData` (form data class, IQ shape), `Form\FillTimeToken` (signed `started`, at least 3 s, no maximum age). Templates `inquiries/_form`, `inquiries/_confirmation`; styles `assets/styles/inquiries/_form.scss`. `_actions.html.twig` links "Verfügbarkeit prüfen" to `#anfrage`, else `#kontakt`, else omits it.
- **Controller order:** honeypot `website` (303 to the confirmation, nothing stored) → form (422, values kept) → fill time (422) → limiter `inquiry_submit` (5 per hour per IP, sliding window, pool `cache.inquiry_submit`, 429 + `Retry-After`) → `SubmitInquiry` → `DeliverInquiry` → `InquiryEvents::submitted()`. A failed venue mail gives 503 with the values kept.
- **`InquiryData` and `EMAIL_SHAPE`:** strict `Email`, then `EMAIL_SHAPE` (`/^[\x21-\x3F\x41-\x7E]+@[^@\p{Z}\p{C}]+$/Du`) in `Assert\Sequentially`, one shared message. An ASCII local part (a non-ASCII one needs SMTPUTF8), no Unicode separator or control character anywhere, an IDN domain stays valid. It is deliberately stricter than `chk_inquiries_email`, because Postgres' `\s` is Unicode-aware and PCRE's is not; `InquiryEmailRuleTest` pins that the form may refuse more than the CHECK, never less. Also `occasion` `NotNull`, and `message`, `firstName`, `lastName` refuse NUL or control characters.
- **`SubmitInquiry` / `DeliverInquiry` (commit boundaries):** two commands, two transactions on `command.bus`. `Command\SubmitInquiry` (business rules: venue published with an address, guests 1–5000, date not before today in Europe/Zurich; exceptions `InquiryNotAccepted`, `GuestCountOutOfRange`, `EventDateInPast`) commits the row before any SMTP call and returns `ValueObject\SubmittedInquiry(id, isRepeat)`. `Command\DeliverInquiry` runs in its own transaction, sends the venue mail and, only if that went out, the guest copy, records each outcome and returns `ValueObject\DeliveryOutcome`. Each mail is a nested dispatch (a savepoint). The venue mail catches `TransportExceptionInterface|RfcComplianceException|HandlerFailedException`, because `Mailer::send()` unwraps only transport errors (`failed`/`skipped`, 503); anything else bubbles with the row `pending`. The guest copy catches `\Throwable`: nothing may undo a sent venue mail, and a render error arrives unwrapped. Delivery is synchronous; `config/packages/messenger.yaml` has no transport.
- **Double submit:** one guest action is one row, on the server, because the form has no JS and PHP finishes a POST the browser cancelled (nginx 499).
  - `ValueObject\InquiryFingerprint`: HMAC-SHA-256 with `kernel.secret` over the venue id and the normalised payload (NFC, collapsed whitespace, lower-case e-mail; case in the text stays significant, so a corrected typo is a new inquiry). Not a person key, purged with the row.
  - `SubmitInquiryHandler` takes `pg_advisory_xact_lock(hashtextextended(fingerprint, 0))`, then looks for the newest row with that fingerprint in the last 10 minutes. Found: returns it with `isRepeat: true`, whatever its status. Not found: inserts. A session-level lock would survive under `persistent: true` or a pooler, so transaction-level only.
  - `DeliverInquiryHandler` claims the row (`InquiryRepository::claim()`, `SELECT … FOR UPDATE`) and holds that claim until commit, across both SMTP calls, so a repeat waits for a delivery in progress and answers with the real outcome.
  - Repeats by row state: `sent` sends nothing and gives the same 303, also when the guest copy is `failed`, which is never re-sent; `failed` delivers the same row again (303, or 503 if it fails again); `pending` with a free claim means the earlier delivery ended without a result, so it delivers too.
  - `SubmitInquiryConcurrencyTest` forces the overlap with two real processes (`tests/Support/Inquiries/post-inquiry.php`) that it holds on the lock until `pg_locks` shows both blocked.
- **`InquiryEvents` and `InquirySubmitted`:** `Inquiries\Event\InquirySubmitted` (`inquiryId`) is published by the guarded `Inquiries\Event\InquiryEvents::submitted()`, which catches `\Throwable` per dispatch and logs. The controller calls it from a `finally` after both commits, for **new rows only** (`isRepeat` false), so one row gives one event, also when the venue mail failed. Honeypot hits never reach it. **Deviation from this cycle's wording:** the spec says the handler publishes the event after the transaction commits, which a handler cannot do inside its own `doctrine_transaction`, so the controller does. Event layer `Event` added to `tests/Architecture/ClassModifiersTest.php`, `.claude/rules/php-architecture.md` and `CLAUDE.md`.
- **#29 and retention:** #29's `crm_inquiry_links.inquiry_id` uses `ON DELETE CASCADE`; research chose the cascade over the spec's other option, so there is no `InquiryPurged` event. `app:inquiries:purge [--dry-run]` (`Console\PurgeInquiriesCommand` → `Command\PurgeInquiries`) deletes by `created_at` after `%inquiries.retention_months%` = 12.
- **`symfony/security-csrf`:** Composer `symfony/security-csrf: 7.4.*` (brings `symfony/security-core`, `symfony/password-hasher`). Stateless: `config/packages/csrf.yaml` has `form.csrf_protection.field_attr: {}`, so no JS double-submit cookie; the `_token` value is the constant `csrf-token` and the real check is `Sec-Fetch-Site`, else `Origin`/`Referer`. The form therefore fits a cached page. A POST with none of those headers is refused, so application tests send `HTTP_SEC_FETCH_SITE: same-origin`. Flex skipped the recipe, so `symfony.lock` has no entry.
- **Mail:** `Inquiries\Mailer\InquiryMailer` (`sendToVenue()` returns the envelope recipient, `sendToGuest()`). Domain `inquiries`: templates `templates/inquiries/email/{venue,guest,_facts}.html.twig` on the shared layout `templates/emails/{layout,_blocks}.html.twig`; the text part comes from `App\Shared\Mailer\EmailHtmlToTextConverter` (`twig.mailer.html_to_text_converter`, class prefix `hl-`, #30 reuses all of it). From is `MAILER_FROM` (`mailer.yaml` `headers`), the guest copy's Reply-To `%site.contact_email%`, the venue mail's Reply-To the guest. `INQUIRY_RECIPIENT_OVERRIDE` redirects venue mails only and adds `X-Inquiry-Original-Recipient`: `.env` leaves it empty, `.env.dev` and `.env.test` set it, `ProductionRecipientOverrideTest` pins `.env`.
- **Catalogues:** `translations/inquiries+intl-icu.de.yaml` (`form.*`, `occasion.<code>`, confirmation, mails) and the new `translations/validators+intl-icu.de.yaml` (`inquiry.<field>.<rule>`): the validator translates constraint messages in the `validators` domain, not per form.
- **Logs:** `Inquiry {inquiry}: the {mail} mail failed ({exception_class} at {file}:{line}).` and the same shape in `InquiryEvents`. The project has no Monolog, and the default `Logger` drops context outside the message's placeholders. Never the exception's message: it can quote an address.
- **Tests:** `tests/Support/Inquiries/` has `InquiryFormFields`, `SubmitsInquiries`, `RecordingMailer` and `SmtpSink` (a real ESMTP server in a child process without SMTPUTF8; Mailpit offers it and hides that case). `InquiryMailer` must be hand-built with `RecordingMailer`, because `MailerInterface` is inlined in the test container.
- **How later cycles extend it:** #29 subscribes to `InquirySubmitted` and may re-dispatch `DeliverInquiry` for a `failed` row with no lock of its own. #30 reuses the mail layout and converter. #24's preview renders the form; a POST from a draft answers 410. New mail templates use `templates/emails/*`.
- **Deviations:** the event's caller (above). A repeat of a `pending` row waits and delivers instead of answering at once: the brief recommended reuse with nothing sent, but that can confirm a mail that never left. The form does not use `form_label`, because the optional hint needs markup. MSO conditional comments and VML namespaces are dropped from the mail layout, untested in Outlook. `symfony.lock` and `config/reference.php` did not change. `InquiryEventsTest` is in `tests/Unit`.
- **Accepted cost:** a process death or a lost database connection after the SMTP acknowledgement and before the commit, followed by a retry within 10 minutes, mails the venue twice. A duplicate mail in a rare window is better than a lost inquiry behind a false "Danke". Concurrency alone does not double-send: every forced overlap gave one venue mail and one guest copy.
- **Carried forward:**
  - #20, **must not be missed:** set an SMTP timeout. The project sets none, so the only bound is PHP's `default_socket_timeout` of 60 s per wait: on a hanging mail server each attempt holds a php-fpm worker for 60–120 s, the guest gets a gateway 504 and never the 503, and 5 POSTs fill a 5-worker pool for a minute. Set it below the gateway's (about 10 s, or a stream timeout on the transport), repeat the review's S1 and S3, and record Hostpoint's worker count and gateway timeout.
  - #20: set `trusted_proxies` (or `inquiry_submit` allows 5 inquiries per hour for all visitors together; `UriSigner` and the CSRF `Origin` check also need scheme and host right) and `trusted_hosts` (the guest mail's link uses the request host).
  - #20: never set `INQUIRY_RECIPIENT_OVERRIDE` in production; the test cannot see the host's `.env.local`, so smoke-check it.
  - #20: ask whether Hostpoint's SMTP advertises SMTPUTF8 (`SmtpSink` tests without it); schedule `app:inquiries:purge` daily; set `MAILER_FROM`; `APP_SECRET` must be non-empty (fingerprint, fill-time token); measure SMTP (drain-on-terminate above about 2 s).
  - #20: the limiter overruns under parallel POSTs (12 of 12 and 9 of 60 passed against a limit of 5); `symfony/lock` is the fix. A repeat also costs a limiter token, so a double click uses 2 of the 5.
  - #20: add a check for `pending` inquiries older than a few minutes, the only sign of a process death.
  - #29, event facts: the event carries the id only and fires once per row, after both commits, also on `failed` or `pending`. A throwing subscriber stops later subscribers of the same event.
  - #29: read the outcome from `inquiries` when showing it, because a `failed` row can turn `sent` after the event; do not keep a copy. Treat `pending` as a possible state.
  - #29: never copy `fingerprint` anywhere, and copy no inquiry personal data into rows that neither cascade nor fall under `app:crm:purge`.
  - #29, resend: a row left `pending` by a process death or a lost database connection after the SMTP acknowledgement is mailed again by the next delivery, a #29 resend included.
  - #29, backfill gap: a process death during the SMTP wait, plus a retry, leaves a delivered inquiry with no event, because a repeat publishes nothing. A lost database connection still reaches the `finally` and publishes (review S5, S7), but the subscriber then runs right after that database failure and its failure is only logged, so treat it as the same gap. `app:crm:backfill-inquiries` is specified to run only once: schedule it or add a check.
  - #16: 422 and 503 re-renders emit `inquiry_submit` hreflang alternates (the response is `noindex`). The canonical on `?anfrage=gesendet` and a robots `Disallow` are also #16's.
  - ~~#21: the punycode fix for 2 venues.~~ Void: the Wix CSV has 0 non-ASCII e-mails; the 2 addresses came from the crawl, which #21 no longer reads.
  - Later: invalid UTF-8 in `phone` gives a 500 and writes the value to the log (review F-m6; a crafted request only): `Assert\Sequentially` or a charset check, plus one case in `malformedFields()`.
  - Known limit: the guest copy repeats free text to an unverified address; the limiter and honeypot bound the abuse.
  - Wording: "Bitte prüfe deine Angaben." above the 503 message; "Eine Kopie findest du in deinem Postfach" also shows on `sent`/`failed`; consider the original's "Vorschau der Nachricht" and "Tipp" line.
  - **Pending human:** a second form step on the original, if there is one (names and contact are not in its first step); real browsers and the `de-CH` date field; real mail clients (Outlook, dark mode); prod-like Lighthouse.

### 15. `venue-map`

- **Standort map:** a static, keyless facade first (a styled placeholder with the address and an "Karte laden" button). Clicking it swaps in a **Maps Embed API** iframe (`loading="lazy"`, key from `GOOGLE_MAPS_EMBED_KEY`). With no key set (dev, or Q3 open), the button is an "Auf Google Maps öffnen" link to `https://www.google.com/maps/search/?api=1&query=…`.
- No Maps JavaScript API, so no client-side map JS from us.
- **CSP** (#16) allows the Google frame origin; Datenschutz (#18) mentions Google Maps.
- **Done when:**
  - application tests cover both modes;
  - with a dummy key, the iframe URL is correct and HTML-escaped;
  - no request goes to Google before the click (checked in the browser network panel, recorded in the review).

**Outcome (2026-10-30).** Done in commit `venue-map`. No migration, no table, no route, no Composer change, no new catalogue domain, no Makefile target, no CSP change (#16's `frame-src https://www.google.com` already covers the embed). Read `CLAUDE.md` `### Venues` ("Built in #15") before touching the map.

- **Twig:** `{{ venue_map(venue) }}` (`App\Venues\Twig\VenueMapExtension`, `#[AsTwigFunction]`, safe HTML) fills #8's `{% block map %}` in `templates/venues/detail/show.html.twig`. It returns `''` unless the venue has both `lat` and `lng`; the private `location()` is the one "has a map" rule, so no pin is ever guessed from a town or canton. No query, no session, byte-identical HTML for every visitor (S10). Template `templates/venues/detail/_map.html.twig`: a facade (pin icon, venue name, address line when the town is known) and the link "Auf Google Maps öffnen" (`target="_blank" rel="noopener"`, visually hidden "(öffnet in neuem Tab)").
- **`App\Venues\ValueObject\MapLocation`** (`final readonly`): `plusCode()` (Open Location Code, reference integer algorithm, 10 digits), `searchUrl()` (keyless `https://www.google.com/maps/search/?api=1&query=<lat>%2C<lng>`, locale-independent floats), `embedUrl(key, language)` (Maps Embed API `place` mode, `q` = the Plus Code, `zoom` 15, `language` from `LocaleSwitcher`; `q` takes no bare coordinates and `view` mode has no marker).
- **Two modes by env `GOOGLE_MAPS_EMBED_KEY`** (`.env` empty, read with `%env(default::…)%`): keyless = facade + link only. With a key, also "Karte laden" plus the notice "Beim Laden der Karte werden Daten an Google übertragen." (`hidden` until JS runs) and the iframe (`loading="lazy"`, `referrerpolicy="strict-origin-when-cross-origin"`) inert inside `<template data-venue-map-frame>`. `assets/js/venue-map.js` (**480 B** gzip, ≤ 500) unhides the loader, clones the iframe over the facade on click (same box, no CLS) and focuses it. Nothing reaches Google before the click: verified in Claude in Chrome with a dummy key (0 `google`/`gstatic` requests on load, exactly one embed request after the click).
- **Styles:** `assets/styles/venues/detail/_map.scss` (`@use`d from `venues/_detail.scss` before `detail/print`), one box for facade and iframe (18.75rem, 28rem from md), tokens only, `&[hidden]`. **Strings:** `venues` catalogue `detail.location.map.{load, open, new_tab, notice, frame_title}`.
- **Tests:** `tests/Unit/Venues/MapLocationTest.php` (seven reference rows from the OLC `encoding.csv`, a Swiss venue, search URL, locale check, embed URL encoding) and `tests/Application/Venues/VenueMapTest.php` (eight tests: keyless, key in the template, a key with reserved characters encoded and escaped, nothing outside the template points at Google, no coordinates, a town without coordinates, cacheable and inline-free, byte-stable; set the key in `$_SERVER`/`$_ENV` before `createClient()`).
- **Budgets (after the rebase, `make test`):** page JS 14,266 of 15,360 B gzip; `app.css` 9,806 (as `AssetBudgetTest` measures it, without the inline source map) of 25,600 B. vnu 0 errors and Lighthouse 100/100/100 (SEO 69 only from dev's `X-Robots-Tag: noindex`) on a detail page with a dummy key.
- **Deviations (recorded):** the locale test runs under `de_DE.UTF-8`, not the plan's `de_CH.UTF-8`, because `de_CH` writes a decimal point and could not catch a locale-aware float; it is a separate test (`test_search_url_ignores_the_numeric_locale`) so PHPStan does not flag the repeated pure call. Four OLC reference rows beyond the plan's three (poles, longitude wrap both ways), all verbatim from `encoding.csv`. The facade's place is a two-line grid. The 390/1440 px screenshots come from the headless fallback.
- **#24's `coordinatesFromTown` guard: not applied.** #24 (`cms-venues`) was not on main when #15 landed (Branch B), so `location()` checks the coordinates only. **#24's finisher** applies `plans/2026-10-30/venue-map-plan.md` Steps 2.1 and 4.3 `[Branch A]` word for word (`|| $venue->coordinatesFromTown` in `location()`, plus the test that sets `UPDATE venues SET coordinates_from_town = TRUE`), rewrites the #15 `CLAUDE.md` sentence "When #24 lands, …" in the present tense, and adds that test to its Tests bullet.
- **Carried forward:**
  - **#18 (`legal-pages`, marker T8):** append to `legal.privacy.links.text` "Der Link «Auf Google Maps öffnen» auf der Seite einer Location führt zu Google Maps." and add a paragraph `legal.privacy.maps` ("Karten von Google Maps": Google Ireland Limited, loaded only after «Karte laden», nothing sent before; then the browser fetches from Google, which receives IP address and site address and may set cookies; Google's privacy policy). Full text: the #15 research, §"Datenschutz text for #18".
  - **#20 (`prod-deploy`) and the owner (Q3):** production stays keyless until the owner provides a key. Set `GOOGLE_MAPS_EMBED_KEY` in the host env only (never a committed file), restricted to the Maps Embed API and the site's HTTP referrer.
  - **Owner option:** the facade repeats the address line shown just above it; it could show the name only.
  - **Pending human:** Firefox and Safari (`<template>` clone, focus into the iframe), a real phone at 390 px, and a real key once Q3 is answered.

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
- **Back office (#22, S12):** the same headers and CSP cover `/admin`. `admin_island_assets()` gets the nonce, Vite's chunks load from `'self'`, and `connect-src 'self'` covers `/admin/api`. `robots.txt` disallows `/admin`. A test logs in and asserts the CSP on an admin page with an island.
- **Done when:**
  - application tests cover meta, canonical, robots meta per page type, the JSON-LD (validated against schema.org shapes: required keys present, no email key), the sitemap XML (schema-valid, every venue present) and the security headers on every public response;
  - Google's Rich Results Test passes on the deployed preview or with copied HTML (recorded in the review).

**Outcome (2026-10-30).** Done in commit `seo-foundation`, rebased onto #12, #13 and #21. No migration, no table, no Composer change, no new Makefile target or console command. Catalogue domain `seo` filled (titles, descriptions, `breadcrumb.*`, `venue.canton_area`, `error.*`); `listing.meta_*` left `search` and `detail.title`, `detail.title_canton`, `detail.title_no_place` left `venues`. Read `CLAUDE.md` `### Seo`, `### Shared` ("Built in #16") and `.claude/rules/frontend.md` (CSP) before touching meta, JSON-LD or headers.

- **Meta (SEO-1):** `listing_seo(criteria, page)` (`App\Seo\ListingMeta::for()`) and `venue_seo(venue)` (`App\Seo\VenueMeta::for()`, Twig extension `App\Seo\Twig\SeoExtension`) return `App\Seo\ValueObject\PageMeta` (`title`, `description`, `canonical`, `noindex`, `ogType`, `latitude`, `longitude`). A page sets it once and fills its `title`, `meta`, `canonical`, `hreflang` and `jsonld` blocks; `templates/seo/_meta.html.twig` (`seo`, `picture` `?Picture`, include `with_context = false`) renders description, robots, Open Graph and Twitter tags. Listing rules: page 1 clean; unfiltered `?page=N` self-canonical with "– Seite N"; "filtered" is `[] !== $criteria->withPage(1)->toQuery()`, and a filtered state is `noindex, follow`, canonicalises to the clean path and emits no hreflang. Until #17, `?canton=zh` is such a filter, and "Andere suchten auch nach" links there. The listing SEO adds no query (the page still runs #12's 7, `MoreFilterTest`).
- **Titles and descriptions:** `App\Seo\TitleChain::fit(patterns, name)`: `patterns` is `list<[key, params]>` in `seo`, most specific first; the first fit ≤ 60 wins, the last resort cuts the name at a word and keeps the suffix; `{site}` is supplied. Venue chain: `{name} in {town} mieten | Hochzeitslocation`, `{name} in {town} | …`, `{name} | …`; canton only `{name} im Kanton {canton} | …`; region text or nothing `{name} | …`. `App\Seo\MetaDescription::fit()`: ≤ 155, ends at a sentence of at least 70 characters, else at a word with `…`; a `.` after an abbreviation in its `ABBREVIATIONS` list (`St`, `ca`, `Nr`, `inkl`, `bzw`, `evtl`, `ggf`, `Tel`, `usw`, `z.B`, `u.a`, `d.h`, compared lower case) is no sentence end.
- **Venue facts:** `App\Seo\ValueObject\VenueFacts` (every place fact nullable; `hasTown()`, `hasPostalAddress()`, `hasCoordinates()`); `fromDetail()` is the one adapter from `VenueDetail` and now reads `[$venue->place->townName, $venue->place->canton]` (#21's `VenuePlace`), which turns on `addressRegion`, the canton title and `containedInPlace`. `sameAs` is the website plus #31's Instagram. `og:type` is `place` (with `place:location:*`) only with coordinates, else `website`. Share image: `Picture::shareImage()` (`Media\ValueObject\ShareImage`, JPEG at `ImageVariants::shareWidth()`, smallest width ≥ 1200) and `VenueDetail::imageUrls()`.
- **JSON-LD (SEO-2):** `site_jsonld()` (default of `base.html.twig`'s `jsonld` block; children call `parent()`), `venue_jsonld(venue)`, `breadcrumb_jsonld(items)` (`list<{name, url?}>`, the last item without `url`), `listing_jsonld(page)` (`ItemList` of `slug` and `name` only, nothing for an empty page; call it only when `not seo.noindex`). Builders `App\Seo\{SiteJsonLd, VenueJsonLd, BreadcrumbJsonLd, ListingJsonLd}`, escaping in `App\Seo\JsonLdScript` (a venue text cannot close the block). `VenueJsonLd`: `["EventVenue","LocalBusiness"]` with `address` and `aggregateRating` (`bestRating` 10) only with ZIP and town; otherwise `EventVenue` with `containedInPlace` `AdministrativeArea` "Kanton …" when the canton is known; empty keys are dropped. Never `telephone` or `email`.
- **Sitemap and robots (SEO-3):** `App\Seo\Controller\SitemapController`: `sitemap` `/sitemap.xml` (always an index), `sitemap_pages` `/sitemap-pages.xml` (`App\Seo\PagesSitemap::urls()`, the one class listing non-venue URLs), `sitemap_venues` `/sitemap-venues-{n}.xml` (requirement `[1-9][0-9]{0,5}`, 1,000 URLs per file, 404 past the last). Read model `App\Venues\ReadModel\VenueSitemapQuery` (`SitemapFile`, `SitemapVenue`), never a join on `towns`; `lastmod` = newest of `venues.updated_at`, the default-locale translation's and the photos'. Only `loc`, `lastmod`, `image:image`. `robots` `/robots.txt` (`App\Seo\Controller\RobotsController`): `Disallow` `/_search/`, `/merkliste`, `/admin`, `/hochzeitslocation/*/telefon`, `/hochzeitslocation/*/anfrage`, plus the absolute sitemap line. All unlocalized, `public, s-maxage=600, stale-while-revalidate=86400`; scheme and host come from the request. XSDs in `tests/Fixtures/Seo/`.
- **Error pages (SEO-5):** `templates/bundles/TwigBundle/Exception/{error404, error410, error}.html.twig` on `templates/seo/_error_page.html.twig`, styles `assets/styles/seo/_error-page.scss`, strings `seo` `error.*` ("du" voice). No query and no hreflang or JSON-LD, so the 500 page survives a database outage (verified with the database stopped). `seo/_footer_cantons.html.twig` (#17) must stay query-free, or `ErrorPagesQueryFreeTest` fails. `/admin` 403 and 404 render these public pages with `no-store`. They render only with debug off; preview at `/_error/404`.
- **Security headers (SEC-1):** `App\Shared\EventListener\SecurityHeadersListener` (priority −10, **main request only**, because #8's fragment and the error renderer are sub-requests) on every main response, `/admin` included: `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin` (#13's no-JS toggle and #14's CSRF fallback need the same-origin `Referer`), `Permissions-Policy`, HSTS `max-age=31536000` only when `isSecure()`, and on `text/html` the CSP `default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-src https://www.google.com; frame-ancestors 'none'`. A nonce is added to `script-src` only when a template called `csp_nonce()` (`App\Shared\Security\CspNonce`, `App\Shared\Twig\CspExtension`): `/admin` does, through `admin_island_assets(csp_nonce())`; cached public pages never do. A `style` attribute set by `setAttribute` is blocked, CSSOM (`el.style.x`, Vue `:style`) is allowed. `HreflangExtension` emits nothing for a request that is not safe (422/503 re-renders). `site.name`, `site.operator_{name,street,postcode,city}` in `config/services.yaml`.
- **Tests:** `tests/bootstrap.php` wipes `var/cache/test` on every PHPUnit start, so `createClient(['debug' => false])` sees current code; use it for custom error templates and the exact CSP bytes. `CLAUDE.md` Testing now says **never run two `phpunit` processes in one lane** (the second wipes the first's cache). `SubmitInquiryConcurrencyTest` warms the asset mapper in `setUp()` (two children rendering cold raced on Docker Desktop's virtiofs) and prints both children's stderr on a status mismatch. New suites under `tests/{Unit,Integration,Application}/Seo`, `SecurityHeadersTest`, `VenueSitemapQueryTest`; `VenueSeoTest` covers `full()`, `minimal()` and `inCantonOnly()`.
- **#13 walk (Step 9.5, done at rebase):** listing page on lane B under the real dev header and under the exact production policy (proxy), headless Chromium with a violation recorder (controls fire: an inline script with JS, a `style` attribute through the CDP log without JS): without JS, Merken POSTs `/merkliste/{n}`, answers 303 back to the exact page it came from (`?canton=be`), the cookie holds the venue, no CSP message; with `merkliste.js`, the button toggles in place, the same-origin `fetch` POST `/merkliste` answers 204 and re-issues the cookie, zero violations. 19 of 19 pass. Cross-checked in Claude in Chrome (own tab, closed): toggle in place, no console CSP message, nginx logged both sync POSTs as 204 (the extension's network panel showed 503 for one; nginx and php-fpm both logged 204, so that is the tool's reading). `/merkliste` is `Disallow`ed and sends `X-Robots-Tag: noindex, nofollow`.
- **Deviations:** R1 public pages send `script-src 'self'` with no nonce (cached pages cannot carry one; narrows SEC-1's "nonces"); R2 no `telephone` or `email` in JSON-LD; R3 the title chain above instead of DP-1's wording, which overflows 60 characters on every demo venue; R4 the `lastmod` rule above; R5 `og:type=place` only with coordinates; R6 `LocalBusiness` only with ZIP and town. Also: `sitemap_venues` allows up to six digits (a 20-digit value would be a `TypeError`); `VenueFacts::hasTown()`; a fifth inline-script exemption in `tests/Application/Shared/StyleguideTest.php` (JSON-LD); an empty-index `sitemap.xml` still lists `sitemap_pages`; #21's three title tests in `VenueDetailControllerTest` now expect this chain.
- **Pending human:** Google's Rich Results Test on both Step 9.8 snippets (with address; canton only) is **the owner's to run** at `https://search.google.com/test/rich-results` (a third-party form; no agent submits it). Not run; the offline shape checks and `VenueJsonLdTest` pass. Record the result here.
- **Carried forward:**
  - **#15:** `frame-src https://www.google.com` is in the policy; any other origin needs a token in `SecurityHeadersListener`. Render the map only with coordinates.
  - **#17:** add the landing variant as a method on `ListingMeta` (own path canonical; a query filter on it becomes `noindex, follow` with that path, by the same rule), pass the canton pattern to `TitleChain::fit()`, add the landing URLs to `PagesSitemap::urls()` with the same `lastmod` rule, move "Andere suchten auch nach" to canton paths, keep `seo/_footer_cantons.html.twig` query-free (static data or a cached file), and do not assume `VenueFacts.town` for town pages.
  - **#18:** add the UID next to `site.operator_*`; add `/impressum` and `/datenschutz` to `PagesSitemap`.
  - **#19:** `purgeListings()` must purge `/sitemap.xml`, `/sitemap-pages.xml`, `/sitemap-venues-{n}.xml` and `/robots.txt`; weigh `inquiry[started]` (#14's per-render signed timestamp makes the cached detail page non-byte-stable); critical CSS inlined on a public page needs a **hash**, not a nonce. The listener runs at −10, so the proxy stores the headers with the page. `ListingHtmlBudgetTest` passes after the rebase (it was 46,863 B gzip of 61,440 before #12 and #13 landed; do not read the growth since #7 as #16's alone).
  - **#20:** the host's rewrite must send `/sitemap.xml` and `/robots.txt` to PHP (no such files in `public/`); `trusted_proxies` decides the https in canonicals, JSON-LD, sitemap and robots and whether HSTS is sent, so check HSTS on production; add `nosniff` for static `/media` and `/assets` if the host allows. Lighthouse SEO ≥ 95 cannot be measured in dev (`X-Robots-Tag: noindex` from debug is the one failing audit: listing 66, detail 69); measure it after #20.
  - **#23:** whichever of #16 and #23 lands second reruns the real-nonce check on the admin pages (island mounts, script carries the nonce, zero violations under the exact header).
  - **#25:** an upload preview may need `blob:` in `img-src` (one token).
  - Lower-confidence, not fixed: an empty-string `meta_description` wins over the summary (`??`), relevant only if #24's form stores `''`; the phone page is `noindex` but still emits hreflang (#8's, URL disallowed).

### 17. `seo-landing-pages`

- **Canton pages** `/hochzeitslocations/{canton-slug}`: the listing with the canton filter pre-applied as a **path facet**, its own h1 "Hochzeitslocations im Kanton Zürich", title "Hochzeitslocations im Kanton Zürich: N Locations | Hochzeitslocation", a one-sentence intro from a catalogue template, a self canonical, and a breadcrumb. In the filter UI, choosing a canton navigates to the canton path, keeping other filters as query parameters, which are then noindexed.
- **Decide with evidence:**
  - whether to add town pages (`/hochzeitslocations/zuerich/staefa`) for towns with ≥ N venues, and building-type pages (`/hochzeitslocations/schloss`), with a minimum-result threshold to avoid thin pages;
  - how the original's landing pages rank: search a few queries, note the title patterns, and record them.
  
  Default: canton pages + building-type pages for types with ≥ 5 venues; town pages only for towns with ≥ 5 venues.
- **Linking:** "Andere suchten auch nach" (LP-5) and the footer list link the landing pages; the sitemap includes them.
- **Done when:**
  - application tests cover every landing type, the thresholds (an under-threshold page is 404), canonical/robots rules and the sitemap entries.

**Outcome (2026-10-30).** Done in commit `seo-landing-pages`, rebased onto #11, #23 and #15. No migration, no `landing_pages` table (every page derives from vocabulary plus counts), no Composer change, no new Makefile target or console command, no new catalogue domain. Read `CLAUDE.md` `### Search` and `### Seo` ("Built in #17") and `.claude/rules/frontend.md` (Overlays: the live-count URL) before touching landing pages, `listing_path()` or an overlay form.

- **Decision with evidence:** one threshold for every type, `%landing.min_venues%` = 5 published venues (`when@dev` 3), on `ListingSql`'s published predicate; under it, or unknown, 404 (no redirect, no noindexed thin page). Google's doorway policy names thin region/city pages. At launch: **13 canton pages** (ZH BE LU SG TG AG SZ VS SO GR OW NW SH), **0 town pages, 0 building pages**; building pages switch on by data with #32's import, town pages when a town reaches 5. The original (eventlokale, 4 requests): canton in the path (`/locations/hochzeit/zuerich`), title "Hochzeit in Zürich: Über 100 einzigartige Locations - eventlokale.ch", h1 "Locations für Hochzeit im Kanton Zürich", no intro, filter tags instead of a breadcrumb, cantons in "Andere suchten auch nach".
- **Routes** on `App\Search\Controller\ListingController` (localized, `#[Cache(public, s-maxage=600, stale-while-revalidate=86400)]`, also on their 404): `listing_landing` `GET /hochzeitslocations/{slug}` (canton slug from `cantons.slug`, else a building slug from `search` `landing.building.<code>.slug`) and `listing_town` `GET /hochzeitslocations/{canton}/{town}`, both with `LandingPage::SLUG_REQUIREMENT`. The request parses `facetQuery()` + query, the path wins per parameter (`/hochzeitslocations/zuerich?canton=be` is Zürich).
- **Read side (Search):** `App\Search\ReadModel\LandingPages` (`kernel.reset`, one memoized `UNION ALL` statement; `all()`, `cantons()`, `towns()`, `buildings()`, `bySlug()`, `byTown()`, `statement()` at 1.92 ms at ×10), `LandingPage` (`type`, `slug`, `venues`, `canton`, `cantonSlug`, `townId`, `townName`, `building`; `facetQuery()`, `consumes()`), `App\Search\ValueObject\LandingType`, `App\Search\LandingPaths` (`locate()`, `url()`, `path()`: the path-facet rule — town page for a live `place` with `radius=0`, else canton page, else exactly one live `building`), `App\Search\ReadModel\LandingFooter` (pool `cache.landing_pages`, no expiry, item `footer_cantons`, written only when the canton list changes, read never fails). `TopCantonsQuery`, `CantonCount` and their test are removed; the listing still runs 7 queries.
- **Twig** (`ListingExtension`): `listing_path()` / `listing_path_without()` are landing-aware; **`listing_form(criteria, params)`** → `{action, except, count}` for every overlay form except the region form (action = landing path without the form's own params, `except` = params + consumed facets, `count` = `_search_count` URL ending in `?` or `&`); `landing_canton_paths()`, `landing_links(?LandingPage)` (LP-5, live pages only, current one left out), `landing_breadcrumb(?LandingPage)`. Seo: `listing_seo(criteria, page, landing = null)`, `footer_landing_cantons()`. Component `components/_breadcrumb.html.twig`; `search/_hero.html.twig` takes `intro`, `breadcrumb`.
- **Meta and sitemap (Seo):** `App\Seo\ListingMeta::for(…, ?LandingPage)`: canonical = landing path of the criteria (`?page=N` only unfiltered); `noindex, follow` when any parameter besides `page` remains or the facet came from the query (`/hochzeitslocations?canton=zh` → canonical `/hochzeitslocations/zuerich`). Titles via `TitleChain` (`seo` `landing.{canton,town,building}.title_*`), descriptions via `MetaDescription::fit()`, `breadcrumb_jsonld()` on landing pages. `App\Seo\PagesSitemap::urls()` appends every live landing page with `lastmod` from `VenueSitemapQuery::newestChangeByGroup()` (`App\Venues\ReadModel\SitemapChanges`). `templates/seo/_footer_cantons.html.twig` renders the cached list, query-free (`ErrorPagesQueryFreeTest` warm and cold).
- **Copy:** `search` `landing.canton.*`, `landing.town.*`, `landing.building.<code>.{slug,heading,intro}` for all 39 building codes (headings ≤ 36), `landing.breadcrumb_label`, `related.town`; `seo` `landing.*`, `breadcrumb.canton`, `footer.cantons_title`. A new building term needs its three `search` keys (`LandingCatalogueTest`).
- **Region overlay:** the form keeps `action="/hochzeitslocations"` and its hidden canton; `data-landing` maps live canton codes to paths and a `submit` handler in `region-filter.js` switches the action and disables the canton field for a live canton, so choosing Bern on `/hochzeitslocations/zuerich?guests=100` lands on `/hochzeitslocations/bern?guests=100`. #11's two guests forms (`#filter-guests-form`, `form.guests-bubbles`) and `_more_form` use `listing_form()`.
- **Tests:** `tests/Support/Search/LandingFixtures.php`; `tests/Integration/Search/{LandingPagesTest, LandingPathsTest, LandingCatalogueTest}`, `tests/Integration/Seo/LandingTitleTest.php`, `tests/Application/Search/{LandingPageTest, LandingLinksTest}`, `admin-ui/tests/public/region-filter.test.js`; extended `ListingSeoTest`, `SitemapTest`, `VenueSitemapQueryTest`, `ErrorPagesQueryFreeTest`, `MoreFilterTest`, `ListingControllerTest`, `ListingExtensionTest`, `ListingHtmlBudgetTest`, `GuestsFilterTest` (landing case).
- **Measured after the rebase onto #11, #23 and #15:** JS (`AssetBudgetTest`, gzip -9n): `region-filter.js` 4,240 → 4,400 B, `live-count.js` 1,130 → 1,122 B; the 6 KB group `overlay.js + live-count.js + region-filter.js` **6,132 of 6,144 B** (12 B left), page JS **14,261 of 15,360 B** (with #11's `guests-filter.js` and #15's `venue-map.js`). HTML (`ListingHtmlBudgetTest`, `SEARCH_PERF_REPORT=1`, 48 venues in 48 ZH towns, full overlays): listing **51,418 B**, canton path **51,548 B** gzip of 61,440 (±250 B factory randomness). Queries (`MoreFilterTest`): listing 7, canton 7, building 7, town 8 (#11 adds none). Suites: `GuestsFilterTest` 20 tests (incl. the canton landing case), `LandingLinksTest` 8, `ListingSeoTest` 11, `ListingControllerTest` 22, `AssetBudgetTest` 6, all green; `make test` PHPUnit OK (2,060 tests after #15), `make admin-test` 14 files green (`region-filter.test.js` 6, `merkliste.test.js` 19). Browser (Claude in Chrome, own tab, lane B, threshold 3): on `/hochzeitslocations/zuerich` the guests forms post to `/hochzeitslocations/zuerich`, the bubble 80 gave the live count `/_search/count?canton=zh&guests=80` ("1 Resultat anzeigen"), and submitting landed on `/hochzeitslocations/zuerich?guests=80` (h1 Zürich, chips Zürich + 80 Gäste, `noindex, follow`).
- **Deviations:** landing definitions live in `Search`, not `Seo` (`listing_path()` builds their URLs and Search may not depend on Seo); `LandingPage::$cantonSlug` (a town URL needs it without a second lookup); `LandingPages` takes no `KnownTermsQuery` (codes come from FK-pinned `venue_terms`); the live-count URL contract now ends in `?`/`&` (`live-count.js` appends the form data; otherwise a canton page's overlay would count every canton); `title_page_bare` patterns so a numbered page keeps its number; a `pageshow` re-enable in `region-filter.js` (bfcache left the canton disabled, so the count dropped it), against the plan's "no pageshow listener".
- **Pending human:** Firefox and Safari for the submit handler and `pageshow`; Google's Rich Results Test for `BreadcrumbList` on a canton page; screenshot comparison 390/1440 of `/hochzeitslocations/zuerich` and `/schloss` (headless shots in the worktree, owner to confirm); the Back (bfcache) path in a real browser.
- **Carried forward:**
  - **#16's `TitleChain::cut()`** drops the last word even when the name fits and always appends "…" (#16 code, not fixed; #17's chains avoid the case).
  - The intro shows the unfiltered landing count while the results are filtered (`/hochzeitslocations/zuerich?guests=100`; the page is `noindex` then). Owner's UX call.
  - **#18:** add `/impressum` and `/datenschutz` to `PagesSitemap::urls()` beside the landing pages.
  - **#19:** `purgeListings()` must purge every landing path (canton, town, building) plus #16's sitemap paths; the footer item needs no purge (rewritten on change).
  - **#26:** no `landing_pages` table: `content_blocks` keyed by landing page, threshold `%landing.min_venues%`, URLs by route name `listing_landing`/`listing_town`, its own counting SQL (Cms may not use Search), falling back to the catalogue keys above.
  - **#32:** building pages switch on with its import. Every overlay form added later takes `listing_form()`; the region group (`overlay.js + live-count.js + region-filter.js`) has 12 B of its 6 KB left.
  - Later: per-locale canton slugs (hreflang copies route params), setting and canton × building pages, threshold hysteresis, a detail-page breadcrumb to the canton page.

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

**Outcome (2026-10-30).** Done in commit `legal-pages`. No migration, no Composer change, no new Makefile target or console command, no new catalogue domain. Read `CLAUDE.md` `### Shared` ("Built in #18") before touching the legal pages. **The text is a draft for the owner, not legal advice**: the owner must approve it (T7) before go-live.

- **What exists:** `App\Shared\Controller\LegalController` with `impressum` `GET /impressum` and `datenschutz` `GET /datenschutz` (localized, `#[Cache(public, s-maxage=600, stale-while-revalidate=86400)]`, no query, no session, no cookie, indexable, self canonical). Templates `templates/site/legal/{impressum, datenschutz, _operator, _footer_links}.html.twig`; `_operator` is the one address block both pages print, `_footer_links` fills the footer's `nav.footer-legal` on every page (query-free, so the error pages keep working). Styles `assets/styles/shared/_legal.scss`. Every sentence is a key under `legal.*` in the `shared` catalogue; the inquiry form links Datenschutz (`inquiries` `form.privacy_link`).
- **One source per fact:** the operator from `site.operator_{name,street,postcode,city}`, `site.contact_email` and the new `site.operator_uid` (`config/services.yaml`); the inquiry retention from `%inquiries.retention_months%` (12, the purge's own parameter); the cookie lifetime from `LegalController::MERKLISTE_DAYS` (400, pinned to `MerklisteCookie::MAX_AGE_SECONDS`); the Maps sentences quote #15's `venues` labels `detail.location.map.load`/`.open`, pinned through the translator.
- **Sitemap:** `Seo\PagesSitemap::urls()` lists both pages after the landing pages, without `lastmod` (the text changes only with a deploy); the early return for "no landing pages" became a guard.
- **Tests:** `tests/Application/Shared/LegalPagesTest.php` (Q5 facts verbatim, every Datenschutz section, cache headers and no `Set-Cookie`, footer links on listing, detail and both pages, retention and cookie lifetime follow their sources, Maps labels follow #15, and a guard that fails if "TODO", Sentry, Google Analytics, Google Fonts or Mailchimp reaches either page's HTML); extended `SitemapTest` and `InquiryFormRenderingTest`.
- **How later cycles use it:** a new processor, cookie or data flow updates `legal.privacy.*` in the same cycle. Open points are `# TODO owner (Tn)` YAML comments above the key in `translations/shared+intl-icu.de.yaml`; they never render. #19 purges `/impressum` and `/datenschutz` with the other static pages.
- **Deviations:** one template per page with catalogue keys instead of songrequest's per-locale templates (`TemplatesHaveNoRawTextTest`, S4); the abuse sentence (`legal.privacy.abuse.text`) reworded, because the per-IP limiter state stays in the filesystem pool until pruned ("läuft spätestens zwei Stunden nach deiner letzten Anfrage ab, abgelaufene Zähler löschen wir regelmässig"), marker T10 added; the Google Maps section uses #15's research text, as #15 landed first (T8 kept only for Google's address); the shared `_operator` partial; the footer links are muted like #17's canton links (`--c-muted` on `--c-surface-footer`). Literal heading sizes in `_legal.scss` stay (review F-m1: no fixed `--fs-*` token matches; `layout/_header.scss` does the same).
- **Owner markers** (#20 blocks on T1–T8 and T10; #29 on T9):

  | Marker | What it needs | Who resolves it | Where |
  |---|---|---|---|
  | T1 | Neon: name the state of the chosen region (nDSG Art. 19 para. 4), check Neon's data processing terms | #20, owner | catalogue `legal.privacy.hosting.database` |
  | T2 | Hostpoint: company name, data location, how long web logs are kept | #20, owner | catalogue `legal.privacy.hosting.web` |
  | T3 | Confirm the production mail provider | #20 | catalogue `legal.privacy.hosting.mail` |
  | T4 | Sentry: add a paragraph if #20 installs it, with `send_default_pii` off | #20 | Outcome only |
  | T5 | `hallo@hochzeitslocation.ch` exists and receives mail (Q5) | #20 | Outcome only |
  | T6 | `app:inquiries:purge` runs daily (cron), or the 12-month retention sentence is false | #20 | catalogue `legal.privacy.inquiry.retention` |
  | T7 | The owner approves both texts. Points for that read: the iOS/Safari 24-hour case (landing via a link-decorated URL from a tracker-classified domain; the page says 7 days), "Wunschdatum" is optional like the phone, a paragraph on venue operators' contact data from the Wix form, the "du" tone on a legal page | Owner | catalogue `legal.privacy` (top) |
  | T8 | Check Google's controller and address against policies.google.com/privacy; an embed with Q3's key needs more | Owner before go-live (#15) | catalogue `legal.privacy.maps.text` |
  | T9 | CRM paragraph (S13) | #29 | Outcome only |
  | T10 | `cache:pool:prune` runs on a schedule (cron), or expired per-IP counters stay on the server | #20 | catalogue `legal.privacy.abuse.text` |

- **#20's blockers from #18:** T1–T5, the **daily `app:inquiries:purge` cron (T6)** and the **`cache:pool:prune` cron (T10)**; T7 and T8 before go-live.
- **Pending human:** owner approval of both texts (T7); the S11 pass at 390/1440 in a real browser (only the headless Chromium fallback ran, Claude in Chrome would not resize).
- **Later:** Open Graph tags on the legal pages (needs a Seo helper; Shared may not use Seo).

## W7–W8 Launch

#19 runs in W7 beside #26; #20 is W8 alone.

### 19. `performance-caching`

- **Reverse proxy** (S10): Symfony `HttpCache` vs Cloudflare vs both. Decide for Hostpoint + Neon (Q2): `HttpCache` needs only a writable `var/`, and it is what hides Neon's cold start.
  - Implement `Cache-Control`/`Vary`, the `HttpCachePurger` from #9, and a no-session guard (a test fails if any public route starts a session). `/admin` is the one allowed exception (ADM-6); the guard asserts that it is the only one.
  - Prove that a CMS save (#24/#25) purges the detail page, the listing and the canton page through `HttpCache`. #26 runs in parallel and calls the same purger.
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
- **Content and media:** the #21 and #32 content (about 890 venues; #21 alone has ~758 venues, ~9,200 photos and about 43 GB of originals plus derivatives in the Laravel layout) reaches the server as rsync of `CONTENT_ROOT` plus a server-side `app:venues:import`, or as locally generated variants rsynced to `public/media`. Research picks one against Hostpoint's disk quota and CPU limits. Hostpoint's quota may rule out keeping originals on the server.
- **Mailbox:** create `hallo@hochzeitslocation.ch` on Hostpoint (the Impressum contact, Q5) and send it a test mail from outside before go-live.
- **Mail:** a sender on `hochzeitslocation.ch` with SPF/DKIM/DMARC set for Hostpoint's SMTP (or the chosen provider), because inquiries go straight to venues (Q6) and must not land in spam.
- **Cron:** `app:inquiries:purge`; the cache warm-up after import. The CRM cycles add `app:crm:renewal-tasks` and `app:crm:purge` to the same crontab later.
- **Back office (OP-4, S12):**
  - the deploy workflow runs `make admin-build` in GitHub Actions and ships `public/admin-build/` with the release (the server has no Node);
  - PHP `upload_max_filesize` / `post_max_size` ≥ 26 MB and `max_execution_time` ≥ 60 s for `/admin` uploads; gd on Hostpoint encodes what #5's `availableFormats()` reports, and CMS uploads are processed on the server, so `MEDIA_ROOT` lives on the server with enough quota for new originals (the legacy originals may stay local, per #5's carried-forward note);
  - the session directory (`var/sessions`) is writable and survives deploys;
  - the TOTP encryption key and the first `admin` user are created on the server; the owner enrols 2FA;
  - the backups now include `MEDIA_ROOT` and are restore-tested, because the database is the master (S8).
- **Monitoring and backups** (OP-3): Sentry (EU region, `send_default_pii: false`, prod only); DB backups (Neon PITR or a nightly `pg_dump`, with a restore test); content and media backups.
- **Done when:**
  - the site answers on HTTPS at hochzeitslocation.ch, and the non-canonical host 301s to the canonical one;
  - production runs on the #21 and #32 content, from a fresh Wix export;
  - a test inquiry to a venue the owner controls arrives and is not flagged as spam;
  - a deploy runs end to end through the workflow;
  - a Sentry test error arrives;
  - a backup restore is tested;
  - Lighthouse against production meets PERF-1;
  - #18's placeholders are replaced;
  - the owner logs in to `/admin` in production with 2FA, edits a venue, uploads a photo, and sees the change on the public page after the purge.

## Real content and owner data (W5–W6, runs alongside the feature cycles; required by #20)

### 21. `legacy-data-import`

**Source change (owner, 2026-10-30): this block supersedes the section below wherever they differ. The slug stays.**

- **Source:** only `Locations+Startseite.csv` (247 rows; path passed as an argument, never committed). The three sources below (Laravel DB, crawl cache, SSD), the Selection and the crawl-specific mapping rules no longer apply. No other data of any kind goes into production content, and nothing is guessed: the CSV has no street, ZIP, town or coordinates, and the 2 streets and 2 ZIPs in its texts are not addresses.
- **Schema (this cycle owns it, a design change like S12's):** `venues` address, town, canton and coordinates become optional, with a hand-written migration; `docs/content-format.md` and its schema follow. The rule that the canton is always the town's canton holds when a town exists. Without a town, the canton is set only when `Region` names exactly one canton.
- **`Region`:** 223 venues name a canton, and `Rümlang` is a town in ZH (224). `Basel`, `Appenzell`, `Romandie` and `Schweizweit` (19) get no canton; research decides how their text is kept for display. `Liechtenstein` (4) is skipped (Q13), so 243 venues go live.
- **Every read path must handle a missing town, canton and coordinates:** cards, the detail page, similar venues, listing filters, radius SQL, sitemap and JSON-LD. Run this cycle after #10 and #14 land, because it touches the same files.
- **Output:** the base folders for #9 (name, canton, capacity, contact, text). #32 overlays facts and photos.
- **Later cycles:** the Ort/PLZ tab and radius (#10), `access`, rooms, area and height (#12), the map (#15), town landing pages (#17) and the address in JSON-LD (#16) have no data until staff enter addresses (#24, optional fields). **Owner decision (2026-10-30):** the Ort/PLZ tab is hidden until venues have towns, and filters without data (`access`, rooms, area, height and the like) are hidden rather than offering a search that always returns 0. The code stays and returns when the data exists. #21 does this for #10's tab; #12 builds its filters the same way.
- **Defaults:** `tier` is `basic` for every venue (Q14). Contact person and Instagram stay empty.

- **Q4 answered:** copy the content from the Laravel project. It becomes the production content (S8).
- **Output:** a console command `app:legacy:export-content` (in `Venues\Console`; it may be deleted once production runs on it). It reads the legacy sources and **writes #9's content folders** into `CONTENT_ROOT`. It never writes to our DB directly, so #9's import stays the only write path. Then `app:venues:import` loads them.
- **Sources** (research reads all three and picks the best source per field):
  1. **The Laravel project's MariaDB** (`../hochzeitslocation`, `docker compose up -d db`, port 3307): 758 `source=eventlokale` venues, rooms, terms, towns, Spatie `media` rows with their `custom_properties.source_url`. Its vocabulary was remapped to that project's own wedding taxonomy (`database/data/taxonomy.php`), which is **not** the eventlokale vocabulary we copy 1:1.
  2. **The raw crawl cache** in `~/media/hochzeitslocation-import/eventlokale/`: `venues/` with 5,128 JSON payloads from `/api/guest/location/{id}/get`, plus `all-taxonomies.json` and `index.json`. These carry the **original eventlokale term ids**, which map 1:1 to our vocabulary from #4. They also carry rooms with floor material, the FAQ fields, distances, and possibly the rating score, count, highlights and comments, which the Laravel import may have dropped. Prefer this source for vocabulary, rooms, FAQ, distances and ratings.
  3. **The photos** on the external SSD: an APFS sparse bundle, `MEDIA_ROOT=/Volumes/hl-media`, mounted with `hdiutil attach /Volumes/PortableSSD/hl-media.sparsebundle` (see `../hochzeitslocation/docs/eventlokale-handoff.md`). It holds 9,298 originals in the Spatie layout. Copy originals only; #5 regenerates our variants.
- **Selection:** the 758 venues the Laravel import kept (wedding-suitable, in Switzerland, with a known canton). Record the mapping and drop reasons in a report file. The export also takes a list of extra crawl ids (`--also=<file>`): #32 names the ~111 owner venues that the wedding filter dropped, and they are exported the same way. Without the file, the export is the 758.
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

**Outcome (2026-10-30).** Done in commit `legacy-data-import`. Address, town, canton and coordinates are optional on `venues`, every read path works without them, and the owner's Wix CSV becomes #9 folders. No Composer change, no new route, no catalogue domain, no Makefile target. The exporter never touches the DB, so `app:venues:import` stays the only write path (S8). Read `CLAUDE.md` `### Venues` and `### Search` ("Built in #21") and `docs/content-format.md` §Generated folders before touching this. The old Done-when above (Schloss, Hotel, Utopia Aarau, the eventlokale comparison) fell away with the source change; the spot-check is listed under Pending human.

- **Schema:** one migration, `Version20261030183112`. `venues.zip`, `town_id`, `canton_code`, `lat`, `lng` are nullable (`street` already was). New CHECKs: `chk_venues_address` (`town_id` and `zip` together, `street` needs a town), `chk_venues_coordinates` (`lat` and `lng` together), `chk_venues_town_canton` (a town has a canton). New column `venue_translations.region` (`VARCHAR(60)`, verbatim): the place of a venue without a canton. `down()` restores NOT NULL and fails loudly while town-less venues exist, and never deletes them; on demo data the way back is `make db-fresh`.
- **Write side:** `Venue::__construct()` and `relocate()` take `?Town`, `?string $zip`, `?string $street`, `?float $lat`, `?float $lng`, and a last `?Canton`; the town wins over a passed canton. `VenueTranslation::changeRegion()`/`region()`. `docs/content-format.md` and its schema: only `name`, `tier`, `capacity.max` and `translations.de.summary` are required, `address.canton` is an enum of the 26 codes, `translations.<locale>.region` is new, and `ContentReader` rejects half an address. `SlugAllocator` goes `x`, `x-2`, `x-3` without a town.
- **Read side:** `App\Venues\ValueObject\VenuePlace` (`final readonly`, `TranslatableInterface`; public `townName`, `canton`, `region`; `of()`, `isKnown()`): `{{ place|trans }}` gives the town, else `Kanton Zürich`, else the region text, else `''`. `VenueDetail::$place`, `ListingCard::$place` and `SimilarVenue::$place` replace `$townName`; `VenueDetail` has nullable `street`, `zip`, `lat`, `lng`. `VenueDetail::hasLocation()` (town, coordinates or distances) guards `#standort` and its nav item. `LEFT JOIN towns` everywhere; `TopCantonsQuery` skips NULL cantons; `SimilarVenuesQuery` ranks with `(…) IS TRUE`, because a NULL canton sorts first under `DESC`; radius and `place` simply exclude town-less venues. `venues` catalogue: `place.canton`, `detail.title_canton`, `detail.title_no_place`, `detail.actions.share_text_canton`. Title and share text have three forms (town, canton, name alone), a placeholder until #16's DP-1. `search/_card_facts.html.twig` takes `place`.
- **Hidden Ort/PLZ tab:** `App\Search\ReadModel\FilterDataQuery::has($filter)` is a memoized `SELECT EXISTS` on `ListingSql`'s published predicate (`FILTERS`, today `'place' => 'v.town_id IS NOT NULL'`; an unknown key throws). Twig: `filter_has_data('place')` on `ListingExtension`. While it is false and `criteria.place` is null, `_region_form.html.twig` adds `.region-filter--canton-only` (CSS hides the tablist and the Ort panel) and `disabled` to the two Ort fields. All markup stays, because `region-filter.js` reads it unguarded; `assets/js` did not change. **The tab returns by itself:** the first published venue with a town (#24, or a folder with an address) makes the `EXISTS` true, with no code or config change. A bookmarked `?place=` keeps the full form for that request, with its removable chip. Demo data keeps the tab visible, because DemoStory has 56 venues with a town.
- **Export:** `app:legacy:export-content <csv> [path] [--dry-run]` (`App\Venues\Console\ExportLegacyContentCommand` → `App\Venues\LegacyContentExporter`; `path` defaults to `CONTENT_ROOT`). `Venues\WixExport` reads the CSV (BOM, multi-line fields; a shifted row or a missing column fails the file), `Venues\WixRichText` the rich text, `Venues\LegacyVenueText` holds `description()` (rules 1–4), `summary()` and `key()`, `Venues\LegacyVenueMapper` maps one row (`Region` → canton or region text, Liechtenstein skip, phone to E.164, bad contact values dropped and noted), and `Venues\LegacyFolderWriter` writes atomically and byte-stable. Also `ValueObject\Content\{LegacyVenue, LegacyExportReport}` and `Exception\InvalidWixExport`, whose messages never quote a cell. It owns exactly `name`, `capacity.max`, `address.canton`, `contact.{phone,inquiry_email,website}`, `translations.de.{summary,description,region}`; every other key survives a re-run, and `tier: basic` is written only when the file has no `tier`. Keys are `<ascii-name-slug>-<first 8 of the Wix id>`, and a folder is found again by that ending. An unresolved row writes nothing and the run exits 1, while the other rows are still written; a Liechtenstein skip is not a failure. Console output holds counts and `<key>: <reason>`, never a contact value.
- **Counts on the real CSV:** 247 rows, **243** venues, **224** with a canton (18 cantons, 8 have no venue), **19** with region text (`Basel` 14, `Schweizweit` 2, `Romandie` 2, `Appenzell` 1), **4** skipped (Liechtenstein), 0 unresolved. All 243 published and `basic`; 0 towns, ZIPs or coordinates; 0 photos, 0 rooms; 0 venues without an inquiry e-mail, 240 with a phone (1 dropped, 2 empty), 242 with a website; 0 contact persons, 0 Instagram. Descriptions by rule 232 / 1 / 8 / 2; summaries 207 sentence / 30 word cut / 4 whole / 2 `Ambiente`. A second export and import change nothing (243 unchanged on both). All 243 detail pages answer 200, the listing shows 243 with 3 cards on page 6, `canton=zh` shows 52.
- **Load the real data into a local lane** (the owner's review; main checkout or a worktree, it replaces that lane's dev DB):
  ```sh
  mkdir -p content/owner && cp ~/Downloads/Locations+Startseite.csv content/owner/
  make db-fresh
  make sf c="app:geo:import-towns --no-interaction"
  make sf c="app:legacy:export-content content/owner/Locations+Startseite.csv --dry-run"   # 247 rows, 243 mapped, 4 skipped, 0 unresolved
  make sf c="app:legacy:export-content content/owner/Locations+Startseite.csv"
  make sf c="app:venues:import"                                                            # 243 created, 0 failed
  ```
  **Back to demo data:** `rm -rf content/owner content/venues && make db-fresh && make seed`. `make seed` alone does nothing useful, because DemoStory skips when venues exist. **Warning: `content/` and the CSV hold real contact data and never get committed.** `/content/` is git-ignored, so never `git add -f` it, and no real value goes into docs, tests or fixtures.
- **Demo data and tests:** DemoStory's venues 52–55 (0-based) have the four town-less shapes, with the faker sequence unchanged: 60 venues, 56 with a town, 58 with a canton, 1 with a region, so `make seed` needed no change. `VenueFactory::withoutLocation()`; `DetailVenues::{withoutPlace, inCantonOnly, inRegionOnly}()`; `tests/Support/Content/WixCsv.php` builds a fictional CSV at runtime. `make test` was 1217 tests before the rebase.
- **Decisions taken (plan):** the fact labels are the 19 folded forms measured on the file (R1). Every text gets NFC, U+200B is stripped (R2), rich-text lists are walked recursively, `IMAGE` skipped with its caption (R3). The sentence boundary skips ordinals and abbreviations, and a sentence really ending in a number falls back to the boundary before it (R4). `tier` is not owned, so #32's value survives (R5). A bad contact value is dropped and reported, never repaired (R6). An unresolved row is reported and exits 1 (R7). The Kanton input gets its top margin from the modifier (R8). No `symfony/filesystem`: temp file plus `rename` (R9). Kept from research, reversible: the 19 venues without a canton never match a canton filter, the 14 `Basel` ones included.
- **Deviations:** summaries are 207 / 30 against the plan's 208 / 29, one more word cut, the safe direction. The BOM is skipped at stream level, because the real file puts it before a quoted `"Name"`. NBSP-only paragraphs are dropped (497 in 162 rows rendered as gaps). The card placeholder became a link (`_card.html.twig`), because the grey area of a photo-less card hit no link. `LegacyVenueText::key()` and `LegacyVenue::skipped()` are additions. `DetailVenues` signatures differ from the plan. Real Chrome stayed at 500 px, so the exact 390 and 1440 px checks ran headless. `docs/content-format.md` now says a town's canton must match, per review F-m4: a mismatch fails the venue as an unknown town, which matters to #32.
- **Carried forward:**
  - **#12:** `place` goes first in `FilterDataQuery::FILTERS`, one constructor argument on `ListingExtension`, both Twig functions (`filter_has_data`, `filter_terms`), per its Variant B. #12 adds its keys here, so hiding stays one mechanism.
  - **#13:** keep `LEFT JOIN` at its rebase (`CARD_TOWN_JOIN = 'LEFT JOIN towns tw ON tw.id = v.town_id'`, `v.canton_code, t.region` in `CARD_COLUMNS`, one `cardsByNumbers()` test with a town-less venue), and keep the placeholder link in `_card.html.twig`.
  - **#15:** render the map only with coordinates; `{% block map %}` now sits inside the conditional `#standort`.
  - **#16:** the one adapter line in `Seo\ValueObject\VenueFacts::fromDetail()` is `[$town, $canton] = [$venue->place->townName, $venue->place->canton];`. `address` and `geo` in JSON-LD only when present. `detail.title_*` are placeholders for DP-1.
  - **#17:** no town pages until venues have towns.
  - **#24:** depends on #21 (overview row and section updated). Town, ZIP and coordinates are optional in CMS-2; `RelocateVenue` handles canton-only; `cms_edited_at` protects staff addresses from an import that omits them. A town without coordinates is never found by radius > 0, while the tab counts it as data: require coordinates with a town, or fall back to the town's centroid.
  - **#32:** reuse `WixExport`, `WixRichText` and `LegacyVenueText` (with `key()`), stay off the owned paths above, set `tier`; `contact.instagram` stays absent (0 in the CSV). Keys ending in `-<id8>` are stable. Review findings to fix there: F-m1 (a failed run leaves an empty `venues/` directory: drop the `mkdir` in `ExportLegacyContentCommand`, the writer already creates it), F-m2 (an `Ambiente` summary is not capped at 200; the longest real value is 31), F-m3 (an unresolved row's folder is also reported as stale).
  - **#20:** deploy runs the migrations, then the CSV export and `app:venues:import`. The `--prune` guard stays yours.
- **For the owner:**
  - 243 identical grey boxes are acceptable as a stopgap only, so stay non-public until #32 delivers photos.
  - 4 descriptions hold a phone or an address verbatim (S14, no rewriting; the export flags them). 1 of them is a public authority's landline, which the review judged no privacy problem. The 2 own numbers in texts bypass #8's phone reveal, the venue's own choice of words.
  - 1 description opens with house rules and prices. 5 are keyword lists. Two owner summaries are 6 and 10 characters. All verbatim, nothing invented; #32 can do better from the facts.
- **Pending human:** a five-venue spot-check (one canton-only, one each of `Basel` and `Schweizweit`, one rule-3 description, one `Ambiente` summary; commands above), Firefox, Safari, a real phone (the hidden tab), and a visual pass in real Chrome at desktop width.

### 31. `venue-wedding-facts`

W5. Depends on #8 (detail page) and #9 (content format, Venues write side). Read S14 first. It is the only W5 cycle that alters `venues`, `venue_translations` or `taxonomy_terms`, and it is a Venues cycle: it changes nothing in Search, so filters and cards stay with #33.

- **Schema** (one hand-written migration, additive, every new column nullable):
  - `venues`: `price_level`, `capacity_seated`, `capacity_standing`, `party`, `has_curfew`, `curfew_at`, `instagram`, with CHECKs (the capacities 1–50000, `instagram` starts with `https://`, `curfew_at` only with `has_curfew`).
  - `venue_translations`: `ambience` (≤ 60), `capacity_note`, `party_note`, `opening_hours` (≤ 250 each).
  - `taxonomy_terms`: extend `chk_taxonomy_terms_term_group` with `ceremony`, `amenity`, `dining`, `vendor`, `usage`, `language`, and insert S14's rows. Existing rows do not change.
- **Vocabularies:** enums `PriceLevel` and `PartyAllowed` and the new `TermGroup` cases in `Venues\ValueObject`, labels in the `venues` catalogue (`price_level.*`, `party.*`, `term.<group>.<code>`, `term_group.*`), and `VocabularyChecksTest` pins them to their CHECKs. German labels are the form's wording ("Zivile Trauung möglich").
- **Entities:** `Venue` gets methods in the style of `changeCapacity()` (no setters), e.g. `changePriceLevel()`, `describePartyRules()`, `changeInstagram()`, and `VenueTranslation` gets the four texts.
- **Content format:** `docs/content-format.md`, `content-format.schema.json` and #9's reader and handler carry the new fields, and `terms` accepts the new groups, so one write path stays (S8). All fields are optional.
- **Detail page (DP-17):** `VenueDetailQuery` (still 3 queries; it lists its term groups explicitly, so the new ones must be added on purpose), `VenueDetail`, a new partial `templates/venues/detail/_wedding.html.twig`, strings under `detail.wedding.*`, the Instagram link in `_contact`, and print per DP-16. Facts that are NULL render nothing.
- **Demo data:** `DemoStory` gives about half of its venues these facts, so both states stay visible. `DetailVenues::full()` and `minimal()` cover both.
- **Done when:**
  - `VenueSchemaMigrationTest` has one rejection case per new constraint;
  - the content import round-trips every new field, and an unknown code fails loudly;
  - an application test covers DP-17 with all facts, with some, and with none;
  - the UI checks of S11 pass on a venue with and without the section;
  - `make db-fresh && make seed` works.

**Outcome (2026-10-30).** Done in commit `venue-wedding-facts`. No Composer change, no routes, no Twig functions, no catalogue domains, no new Makefile targets or console commands. Nothing in `src/Search` or `templates/search` changed: the six new groups have no filter and no card until #33. Read `CLAUDE.md` `### Venues` ("Built in #31") and `docs/content-format.md` before touching these facts.

- **Schema:** one additive, reversible migration, `Version20261030160506`. `venues`: `price_level`, `capacity_seated`, `capacity_standing` (1–50000; the owner's numbers, not compared with `capacity_max`), `party`, `has_curfew` (NULL = unknown), `curfew_at` (`TIME(0)`), `instagram`, all nullable, with `chk_venues_{price_level,capacity_seated,capacity_standing,party,curfew_at,instagram}`. `chk_venues_curfew_at` is `curfew_at IS NULL OR has_curfew IS TRUE`, because the plain form is NULL for a time with `has_curfew` NULL and a NULL CHECK passes. `venue_translations`: `ambience` (≤ 60), `capacity_note`, `party_note`, `opening_hours` (≤ 250 each), kept verbatim. `taxonomy_terms` now holds **13 groups and 146 rows** (56 new, `primary_position` NULL; 147 rows and 12 languages since #32's `ro`); `chk_taxonomy_terms_term_group` lists the groups in `TermGroup` case order. No index: #33 decides after `EXPLAIN`.
- **Vocabularies:** `Venues\ValueObject\{PriceLevel, PartyAllowed}`, and `TermGroup::{Ceremony, Amenity, Dining, Vendor, Usage, Language}`. Catalogue `venues`: `price_level.*`, `party.*`, `term_group.*`, `term.<group>.<code>`, `detail.wedding.*`, `detail.contact.instagram`.
- **Entities:** `Venue::{changePriceLevel, changeSeatingCapacity, describePartyRules, changeInstagram}`, `VenueTranslation::describeWedding(?ambience, ?capacityNote, ?partyNote, ?openingHours)`, bare-name getters. `revise()` and the constructor did not change, so #24 writes the texts with a second call.
- **Content format (the one write path):** the YAML names are `price_level`, `capacity.seated`, `capacity.standing`, `party.allowed`, `party.has_curfew`, `party.curfew_at`, `contact.instagram`, `terms.<ceremony|amenity|dining|vendor|usage|language>` and `translations.<locale>.{ambience, capacity_note, party_note, opening_hours}`, all optional; an omitted field resets to NULL on update. `party` is an object with nullable fields, shaped like `faq`; a missing block means unknown, and a null block (`party:` with nothing after it) is rejected. `curfew_at` is `HH:MM` at every boundary (`to_char(…, 'HH24:MI')` in SQL, `H:i` in PHP), midnight is `'00:00'` and `24:00` is rejected; `ContentReader` reports `/party/curfew_at` without `has_curfew: true`. **#32 writes `24.00 Uhr` as `'00:00'` and keeps the wording in `party_note`.** `ContentFormat::VERSION` stays 1, so #31 alone triggers no re-import.
- **Detail page (DP-17):** `Venues\ReadModel\DetailWedding` (`hasAny()`; `hasCurfew` false counts, because "Keine Sperrstunde" is a known fact) is `venue.wedding`; `venue.instagram` also feeds `hasContact()`. `VenueDetailQuery::detail()` stays at 3 queries and the six groups never join `factGroups`. `templates/venues/detail/_wedding.html.twig` follows `_facts` in `show.html.twig`, reuses the `venue-facts__*` classes (no new SCSS) and prints through `_print.scss` unchanged. The Instagram link is in `_contact` with `rel="nofollow noopener"`.
- **Demo data and tests:** `DemoStory::createWeddingFacts()` runs after the media step and gives the 30 even-index venues the facts, with its own seeded RNG; `DetailVenues::full()` and `ContentSchemaTest::full()` carry every fact, `minimal()` none. Round trip: `tests/Integration/Venues/ImportWeddingFactsTest.php`. `make test` was 813 tests before the rebase.
- **Later cycles:** #24's form "Hochzeit" composes the entity methods above. #33 reads `venue_terms` by code and these columns directly. #16 adds `venue.instagram` to `sameAs`. A new term needs a migration plus its label; `TaxonomyTermsTest` and `ContentSchemaVocabularyTest` fail until the schema enum and the catalogue agree.
- **Decisions taken (research §Open Decisions):**
  1. `amenity` shows as a line "Ausstattung" before Essen. That goes beyond DP-17's list, because FI-7 filters on these codes.
  2. `cs`, `ru`, `hu`, `gsw` ship now as `language` rows, by S14's ≥ 3 rule on the CSV. A new language at ≥ 3 in a fresh export needs a small `INSERT` migration in #32.
  3. `party_note` stays ≤ 250. #32 trims it to what fits and reports the rest.
  4. Instagram uses the `camera` icon with the text "Instagram", because Lucide ships no brand marks (sprite: 39 icons).
- **Deviations:** the plan's `WEDDING_GROUP_ORDER`-style selection became six named lists on `DetailWedding`, since the template fixes the order; no `_wedding.scss` was needed; the catalogue's header comment was corrected, since not all term labels are eventlokale's any more. Plain-text rows in the section use body size and term rows `--fs-small`: acceptable, a design call for the owner.
- **Carried forward:**
  - **Owner, open:** the three manual checks. S11 at 390 and 1440 px in a real browser (only headless evidence exists), the browser print preview, and reading the German labels (`/hochzeitslocation/landgut-rebhalde` has the section, `/hochzeitslocation/seehaus-mittelwil` has none).
  - **#32:** the tokens `laktosefrei`, `bar`, `lift`, `mikrofon` have no code and go to its report.
  - **#33:** the docblock of `Search\ReadModel\KnownTermsQuery` ("about 90 rows") and the "90" in `CLAUDE.md` `### Search` are stale; both were left for it, because #31 changes nothing in Search.
  - **Not measured:** the detail page p95 at demo data ×10 (S11: ≤ 120 ms). It is not in this cycle's done-when, and the query count and shape are unchanged apart from extra columns.
  - **`DemoStoryTest`'s 10 s budget** is load-sensitive and misses when several lanes run. Measured as not caused by #31: `createWeddingFacts()` takes a median of 0.25 s, and the base commit misses the budget at the same rate (2 of 8 at host load 16–27). The budget was not changed; any decision on it belongs outside this cycle.
  - **Pre-existing:** `docs/content-format.md`'s `faq:` comment says a null block is valid, but the schema rejects it, as it does for `party`.

### 32. `owner-content-import`

**Source change (owner, 2026-10-30): this block supersedes the section below wherever they differ.** #32 reads the same CSV as #21 and uses nothing else: the match to the crawl, the `--also` ids and the address steps fall away. It overlays #21's folders with the S14 facts and the Wix photos. The owner said go on #32 and #33.

- **Output:** a console command `app:owner:export-content <csv> [--dry-run]` (in `Venues\Console`; it may be deleted once the CMS is the master). Like #21, it **only writes content folders** into `CONTENT_ROOT`, never the DB, so `app:venues:import` stays the only write path. Then the import loads them.
- **Steps:**
  1. **Parse** the CSV and the Wix rich text of `Vorstellungstext` into a plain description, labelled sections, and an ordered photo list (`Anzeigebild`'s `wix:image://v1/<id>/…` and the `IMAGE` nodes, with `altText`). Both form generations of S14. Unknown sub-headings stay in the description; nothing is dropped silently.
  2. **Map** per S14: fields, `Typ`, `Ambiente` → `setting`, and the token table of the older generation. Parse the two capacity lines and curfew times; what does not parse stays in the `*_note` text. Contact values and `summary` stay #21's.
  3. **Photos:** download `https://static.wixstatic.com/media/<id>` once into a cache in `CONTENT_ROOT/owner/photos/`, keyed by media id, one request at a time with a retry, resumable. Keep the original file; #5 makes the variants.
  4. **Overlay** #21's folder with the paths #32 owns (`docs/content-format.md` §Generated folders). A row without its #21 folder is reported, never created. Tier stays #21's `basic` (Q14).
- **Report** (outside git): counts, unresolved rows, unmapped tokens with their frequency, photos left out, notes cut, skipped venues with the reason. The token list is how the mapping table grows; rerun until nothing frequent is left.
- **Fresh exports:** the Wix site keeps taking entries (9 new in October 2026), so the command is rerun on a new CSV before #20. It is idempotent, and #24's import guard protects CMS edits from then on.
- **Not in the repo:** the CSV, the photos and the report stay under `CONTENT_ROOT`. Tests use a small fictional CSV and rich-text fixture covering both generations, and never call Wix.
- **Done when:**
  - a dry run reports all of the above with zero unmapped `Typ` values and zero unmapped values of the newer form;
  - every venue except the Q13 ones either passes #9's validation or is reported with the missing field;
  - `app:venues:import` loads them, and a second export and import change nothing;
  - integration tests cover a parsed venue of each generation, an overlay that keeps #21's paths, a row without a folder, and a photo left out;
  - the owner spot-checks five venues (one older-form venue with several capacity numbers, one newer-form venue with party and curfew, one from the D7 list, one `Schweizweit`, the one that lost its AVIF cover);
  - the Outcome records the counts: venues overlaid and skipped; photos downloaded and their size; the total venue count.

**Outcome (2026-10-30).** Done in commit `owner-content-import`. The owner's Wix CSV now gives the 243 Swiss venues their S14 facts and their Wix photos, on top of #21's folders. Like #21, the export writes content folders only, so `app:venues:import` stays the one write path (S8). Composer `symfony/http-client: 7.4.*`. No route, no Twig function, no catalogue domain, no Makefile target. Read `CLAUDE.md` `### Venues` ("Built in #32") and `docs/content-format.md` §Generated folders before touching this. The steps, the Done-when and the spot-check list above were rewritten for one source: the crawl match, `--also` and the address step are gone.

- **Schema:** one migration, `Version20261030204015`: `language` term `ro` (position 12; `down()` deletes its `venue_terms` rows first). `taxonomy_terms` now holds **147 rows, 12 languages**.
- **Command:** `app:owner:export-content <csv> [path] [--dry-run]` (`App\Venues\Console\ExportOwnerContentCommand` → `App\Venues\OwnerContentExporter`; `path` defaults to `CONTENT_ROOT`). Run order: `app:legacy:export-content`, then this, then `app:venues:import`. A row without its #21 folder is unresolved (exit 1, nothing downloaded); Liechtenstein rows are skipped (Q13 default). Exit 1 on an unresolved row or a failed download. `--dry-run` writes only the report file and fills no cache.
- **Classes:** `App\Venues\OwnerFactsMapper` (pure; every S14 token table a private const; nothing inferred, unmatched wording to a `*_note` cut at 250 with `…` or to the report), `App\Venues\OwnerPhotoList` (cover `Anzeigebild`, then the rich text's `IMAGE` nodes in order, deduped by media id), `App\Venues\WixPhotoCache` (one request at a time through the scoped client `wix.client`, `retry_failed` 3, `.part` plus length check, 0.25 s pause, resumable), `App\Venues\OwnerPhotoCheck` (leaves a photo out, never fails the venue: `.avif`, a type outside `ContentReader::SNIFFED_IMAGE_TYPES`, above `BATCH_MAX_PIXELS` read from the header, undecodable by gd, duplicate), `App\Venues\OwnerFolderWriter` (hard links, else copies, into `photos/`; deletes unlisted files), `App\Venues\ContentYamlFile` (shared parse and dump flags, also used by #21's writer, so both exports in either order leave `venue.yaml` byte-identical), `App\Venues\ValueObject\Content\{OwnerFacts, OwnerPhoto, OwnerExportReport}`. #21's `WixExport` (`OWNER_COLUMNS`), `WixRichText::images()` and `LegacyVenueText::{sections, folderId}()` were extended, and its review findings F-m1–F-m3 fixed.
- **Owned paths:** `price_level`, `capacity.{seated,standing}`, `party`, `faq.own_catering`, every `terms.*` group but `furniture`, `main_terms.{setting,building,suitable_for}`, `translations.de.{ambience,capacity_note,party_note,opening_hours,parking_text}`, `photos` and `photos/`. `summary` and `tier` stay #21's (all `basic`, Q14 default).
- **Batch memory:** `App\Media\ImageVariantGenerator::raiseBatchMemoryLimit()` raises `memory_limit` to `512M` (never lowers; warns when it cannot), called first by `app:venues:import`, `app:media:regenerate` and the owner export. A 60 MP photo is fatal at 256M and peaks at about 310 MiB at 512M. `BATCH_MAX_PIXELS` = **100 MP** is enforced by the export only. Web uploads keep php-fpm's limit behind #25's `MEDIA_MAX_PIXELS`.
- **Photo cache:** `content/owner/photos/` (git-ignored), 2,142 files, 2.8 GB, downloaded once in 28.8 min. It now lives in the main checkout; a re-run downloads only new ids.
- **Real run (lane A):** 247 rows, **243 venues** overlaid and imported, 4 skipped (Liechtenstein), 0 unresolved, **0 failed**. Photos: 2,143 listed, 2,142 cached, 2 left out (1 AVIF, 1 duplicate), **2,141 imported** (2,131 in 26 min 26 s, plus 10 for the 60 MP venue first), 0 venues without a photo; originals 2.7 GB, variants 2.1 GB, hard links so the folders add 1.2 MB. Facts: price level 243, ambience 243, **seated 193, standing 197**, party 57, no curfew 48, curfew time 74, own catering 15; notes: capacity 89, party 110, opening hours 186, parking 18; `language = ro` 3. Ceremony: `civil_ceremony` 51 (32 of them through the D7 row), `free_ceremony` 45, `rain_alternative` 36, `church_nearby` 18, `registry_office` 17, `chapel` 9. The report lists 1,657 distinct unmapped answers in 10 sections, none from `Typ`, `Preisniveau` or the newer form. A second export and import change nothing. All 243 detail pages 200, every `/media/…` URL exists. **Peak memory 504.9 MiB of 512M with debug on** (300 MiB for the 60 MP venue alone).
- **Load the real data locally** (the owner's review; it replaces that checkout's dev DB, about 30 minutes):
  ```sh
  mkdir -p content/owner && cp ~/Downloads/Locations+Startseite.csv content/owner/
  make db-fresh
  make sf c="app:geo:import-towns --no-interaction"
  make sf c="app:legacy:export-content content/owner/Locations+Startseite.csv"
  make sf c="app:owner:export-content content/owner/Locations+Startseite.csv"   # cache hits, no download
  make sf c="--no-debug app:venues:import"                                      # 243 created, 0 failed
  make seed                                                                     # dev admin; venues untouched
  ```
  **Back to demo data, keeping the cache:** `rm -rf content/venues var/media/content public/media && make db-fresh && make seed`. Never `git add -f` anything under `content/`.
- **Tests:** `tests/Unit/Venues/{OwnerFactsMapperTest, OwnerPhotoListTest, OwnerPhotoCheckTest, ContentYamlFileTest}`, `tests/Unit/Media/BatchMemoryLimitTest`, `tests/Integration/Venues/{ExportOwnerContentCommandTest, WixPhotoCacheTest}` (both form generations end to end through the real reader and handler, byte stability in both orders, bad photos, 404 and resume). `Tests\Support\Http\WixImageResponses` is the test env's `mock_response_factory` and throws on an unknown URL, so no test can reach Wix. `make test` was 2,026 tests before the rebase.
- **How later cycles use it:**
  - **#20:** deploy runs the migrations, then #21's export, this export and `app:venues:import` on a fresh CSV (the cache keeps a re-run cheap). Measure the prod peak of a full import with debug off and only then confirm 512M and 100 MP; confirm `ini_set('memory_limit')` on Hostpoint's CLI, else `php -d memory_limit=512M`. Unverified: Neon's idle-in-transaction limit, since a venue's photos render inside its transaction. Originals and variants are about 5 GB in total. `--prune` stays yours.
  - **#24:** no code here. #32 writes files only, so #24's guard skips an edited venue as a whole.
  - **#25:** keep the pixel cap in the upload's `Assert\Image`. Never add one to `PhotoPipeline` or `ImageVariantGenerator` (41 imported photos are above 40 MP), and never call `raiseBatchMemoryLimit()` in a request.
  - **#33:** 243 venues carry `price_level` and `ambience`; its owner check on real data can run after the load above.
- **Deviations:** F-m1 of the review (the finisher): the capacity grammar takes `max` without the dot and a unit without a space, which moved seated 191 → 193 and standing 193 → 197, the plan's numbers. `curfew_at` is 74, not the plan's 72 (research counted before the `HH:MM` normalisation). `WixPhotoCache` is public in `when@test`; `OwnerPhotoList::fromRow()` takes a by-reference notes list; console photo lines name the position, not the media id. Real Chrome stopped at its minimum window width, so 390 px ran headless.
- **Carried forward:**
  - **F-m2 (memory):** the growth to 504.9 MiB is #9's import with debug on in dev: Doctrine's debug data holder keeps about 2.5 KB per query (67 B with debug off). Run the real import with `--no-debug` (above). #20 measures the prod peak.
  - The two `Intern` answers of the `service` section (13 and 9 venues) stay unmapped on purpose: "in-house" has no vendor code, and the second is the form's own question cut at its first comma.
  - Three near-verbatim full prompt echoes (an inserted `djs`, a missing space, `&` for `,`) slip past `SERVICE_PROMPT` and give those three venues 7–8 vendor codes. Owner's call; low impact, because `vendor` has no filter and shows on the detail page only.
  - Not built (planned out of scope): new codes (`laktosefrei`, `bar`, `lift`, `mikrofon`, Thai, Turkish), a `Nutzung` note, multi-room `Kapazität` sections, `summary` from the facts.
  - **Owner checks:** the D7 spot-check (the 32 `civil_ceremony` venues from "Räumlichkeiten für standesamtliche Hochzeiten", listed at the end of `content/owner/export-report.md`; to disagree, delete that one row in `OwnerFactsMapper` and re-run the export and the import); the data-merge review on five venues (one older-form venue with several capacity numbers, one newer-form venue with party and curfew, one from the D7 list, one `Schweizweit`, the one that lost its AVIF cover); a real-Chrome pass at 390 px.

### 33. `filter-wedding`

W6. Depends on #12 (the overlay and its chips) and #31 (the data). The Search side of S14: it changes no schema.

- **`SearchCriteria`** (the hotspot change of S14): `price[]`, `ceremony[]`, `amenity[]`, `usage[]` and `curfew=none`, each type-checked against known values and dropped if invalid (`toQuery()` stays canonical). `price` is OR, like `setting`/`building`; the term groups are AND, per #6's rule for new term filters. `curfew=none` is `has_curfew = false`.
- **`ListingSql`:** the new predicates once, shared by cards and count, so `/_search/count` is right without further work. `ListingQueryPerformanceTest` stays under 20 ms, and any index goes through EXPLAIN first (a new index here is allowed; the columns stay #31's).
- **Overlay (FI-7):** one section "Hochzeit" in #12's `_more.html.twig`, with the live count and chips as in #12. Only `amenity` codes with enough data appear, a const list in the template's data.
- **Cards (LP-7):** `ListingCard` carries `priceLevel` and `ambience`, and `_card.html.twig` shows the tags. #8's compact similar cards do not change.
- **Done when:**
  - unit tests cover the codec (round trip, invalid values, canonical order);
  - integration tests cover every new filter and the group logic;
  - an application test covers the overlay, the chips and a card with and without tags;
  - the UI checks of S11 pass.

**Outcome (2026-10-30).** Done in commit `filter-wedding`. No migration, no index, no table, no new route, no Composer change, no new catalogue domain, no new Makefile target or console command, 0 B of new JS. Read `CLAUDE.md` `### Search` ("Built in #33") before touching these filters or the card tags.

- **Codec (`App\Search\ReadModel\SearchCriteria`):** `ceremony[]`, `amenity[]`, `usage[]` joined `PARAMS` (term groups, AND inside the group, #6's rule); `price[]` takes `Venues\ValueObject\PriceLevel` values (`moderate|medium|exclusive`, OR, `v.price_level IN (…)`, field `prices`); `curfew=none` is `v.has_curfew = FALSE` (field `noCurfew`), so a venue with `has_curfew` NULL (unknown) never matches; any other `curfew` shape is dropped. Canonical `toQuery()` order: `… surroundings, ceremony, amenity, usage, price, curfew, rooms, area, height, page`; a URL without the new params is byte-identical to before (unit-tested).
- **`SearchCriteria::FILTER_CODES` + `offers(TermGroup, code)`:** the only codes that filter: `amenity` the seven of FI-7 (`overnight`, `dance_floor`, `lake_view`, `panorama`, `outdoor_dining`, `external_caterers`, `wheelchair_access`), `usage` only `exclusive`; an unlisted group filters on all its codes. Used by `fromQuery()` (so `amenity[]=tent` is dropped) and by `FilterDataQuery::terms()` (so no control shows for it). **Deviation from the cycle text** ("a const list in the template's data"): the list lives in the codec, because a code the URL accepts without a control would narrow results invisibly. `FilterDataQueryTest` pins every listed code to `taxonomy_terms`.
- **SQL:** the predicates live once in `ListingSql`, so cards, count and `/_search/count` agree. `ListingQueryPerformanceTest` (×10) now carries the S14 params: heaviest cards 0.20 ms, matching 0.67 ms, flags 0.20 ms, terms 3.88 ms, all far under 20 ms, so no index and no migration (the Overview's "indexes only, if needed" came out as none).
- **`App\Search\ReadModel\FilterDataQuery`:** new keys `price.moderate|medium|exclusive` (bound `:price_<value>`) and `curfew` (a published venue with `has_curfew = FALSE`); `terms()` covers the three new groups through `PARAMS` and `offers()`. Still one flags and one terms statement per request.
- **Overlay (FI-7):** `templates/search/filter/_more_wedding.html.twig` (`criteria`) is the first group after Name in `_more_form.html.twig`: Preisniveau, Trauung (`_more_terms`, `ceremony`), "Feier und Ausstattung" (Keine Sperrstunde, then `usage`, then `amenity` in vocabulary order; its own `chip` macro, since `_more_terms`'s builds `term.<group>.<code>` labels). #12's hiding rule per value: a control shows with data or when active; without any facts the group renders nothing and the overlay stays "Name eingeben" only. `_more.html.twig`'s `params` end with `price, ceremony, amenity, usage, curfew` (pill N, × link, reset, `_hidden_state`). 18 controls with full data (overlay tab stops 52 → 70 at 1440 px). Strings in `search`: `filter.more_price`, `filter.more_wedding`, `card.price_level`.
- **Cards (LP-7):** `ListingCard::$priceLevel` (`?PriceLevel`) and `$ambience` (`?string`, `venue_translations.ambience`), read by `ListingQuery`. `_card.html.twig` renders `<ul class="card__tags">` after the summary only when one exists, price first with a visually hidden "Preisniveau:", both through `components/_badge`; `.card__tags` has `padding: 0` and sits in column 1 under the xl grid. A card without tags is exactly LP-2 (geometry of the 25 untagged demo cards identical before and after at 390/1024/1440 px). #8's similar cards are unchanged.
- **Budgets and pins:** the listing still runs **7 queries** (`MoreFilterTest` unedited and green). `ListingHtmlBudgetTest` now gives every card both tags and renders 90 checkboxes: **49,392 B gzip of 61,440** (was 48,603). Lighthouse prod-like mobile 99 / 100 / 100 / 100; `make validate-html` clean apart from #10's known tooltip warning. `make test` before the rebase: 1,594 tests.
- **Tests:** `tests/Unit/Search/SearchCriteriaTest.php` (round trip, invalid values, canonical order, unchanged URLs, `FILTER_CODES`), `tests/Integration/Search/ListingQueryTest.php` (each filter, group logic, card fields), `FilterDataQueryTest` (new keys, per-level data, curfew, hiding outside `FILTER_CODES`), `tests/Application/Search/WeddingFilterTest.php` (16 tests: overlay, hiding, pill, hidden state, live count = total, tags).
- **Deviations:** no `codes` param on `_more_terms` (#12's carried-forward suggestion): the list is `FILTER_CODES` and `amenity` renders in `_more_wedding`'s mixed section, so the param would have no caller. Only the region form's hidden state is tested, because `_guests.html.twig` is still #7's placeholder. S14's "about 247 of about 890" is superseded: with Q12's CSV-only source, price covers every venue after #32; term filters cover the venues that stated the fact, with no hint text.
- **Carried forward:**
  - **#11:** add the guests form's hidden-state test for the wedding params when `_guests.html.twig` is built.
  - **Not built (planned out of scope):** filters for `dining`, `vendor`, `language`; tags on similar cards; an "only stated facts" hint.
  - **Owner option:** the price tag reads "Mittel" alone visibly; a visible prefix ("Preisniveau: Mittel") is a one-string change. The price tag reuses the tier badge's style, a visual call.
  - **Pending human:** Firefox, Safari, a real phone, a screen reader on the hidden "Preisniveau:", and the owner's real-data check after #32 (every control of the plan's coverage table shows).

## Back office: CMS (W4–W7, before launch)

Read S12 and the design reference `docs/reference/admin/` before any of these cycles. The CMS is a set of `Cms` screens on top of the `Venues` write side; it adds no second way to change a venue (S8).

### 22. `admin-foundation`

W4. Depends only on W1 (#1 fonts and icons, #2 catalogues and route rules).

- **Modules (the S12 design change):** add `Admin`, `Cms` and `Crm` to `ArchitectureRules::MODULES` and to `ModuleBoundariesTest`, with the S2 rows: `Admin` → Shared; `Cms` → Admin, Venues, Geo, Media; `Crm` → Admin, Venues, Geo, Inquiries. Add the three to the deny-lists of the existing public-module rules, so no public module may use them. Add the `Extension` layer. Extend `ModuleBoundariesProbeTest` with one probe (`Cms` → `Crm` fails). Update `CLAUDE.md`'s module table, and add `### Admin`, `### Cms`, `### Crm` stubs to its `## Modules`.
- **Security** per S12 and ADM-1/ADM-2:
  - `admin_users` (`id`, `email` unique on `lower(email)`, `display_name`, `password_hash`, `role` CHECK `admin|editor`, `totp_secret_encrypted` NULL until enrolled, `is_active`, `last_login_at`, timestamps) with entity, repository and user provider;
  - firewall `^/admin` (form login, logout, `login_throttling`, 2FA with enrolment on first login showing a QR code, rendered server-side as SVG so no external service sees the secret), session cookie per S12, idle timeout;
  - `app:admin:create-user`, `app:admin:reset-2fa`, the users screen;
  - a test proves every `/admin` route except login and 2FA requires a fully authenticated user, and that public routes still start no session (S10, ADM-6).
- **Shell** (ADM-4) per the design reference: `templates/admin/base.html.twig` (blocks `title`, `page_header`, `page_actions`, `main`, `aside`, `scripts`), `templates/admin/layout/{_sidebar,_topbar,_flash}.html.twig`, login and 2FA screens, a dashboard at `/admin` built from `DashboardWidget`s (empty-state widget when none), the `admin` catalogue, `assets/styles/admin.scss` with the `--a-*` tokens and the shell partials, and the S10 headers on every `/admin` response.
- **Extension points** (ADM-5): the interfaces of S12 in `Admin\Extension` plus their registries and Twig functions (`admin_navigation()`, `admin_dashboard_widgets()`, `admin_record_tabs(type, id)`, `admin_record_panels(type, id)`), tested with a fixture module.
- **Audit log** (ADM-3): `Shared\Messenger\AuditableCommand` (`auditSubject()`, `auditSummary()`), the `command.bus` middleware writing `admin_audit_log` (`id`, `actor_id` NULL for console, `command`, `subject_type`, `subject_id`, `changes` JSONB, `created_at`), and the audit screen. Console commands record the actor as `console`.
- **Vue toolchain** (S12): `admin-ui/` (Vite, Vue 3, Vitest, ESLint), the `node` tools service, the Make targets, the island loader `admin-ui/src/main.js` + registry `admin-ui/src/islands.js`, the `admin_island_assets()` Twig function (manifest, `modulepreload`, nonce-ready), one demo island (`hello`) with a Vitest test and an Application test that renders its mount point, CI steps, `.gitignore` for `public/admin-build/` and `admin-ui/node_modules/`. The JSON-endpoint convention: `/admin/api/<module>/…`, a CSRF header token, errors as `{"errors": {"field": ["message"]}}` with 422.
- **Lighthouse logged in:** `make lighthouse URL=/admin/... ADMIN=1` uses a test-only login helper (dev env, `ADMIN_DEV_AUTOLOGIN` token) so the admin cycles can measure Accessibility.
- **Done when:**
  - login, 2FA enrolment, 2FA check, throttling, logout, idle timeout, role checks and the users screen are application-tested;
  - the public no-session test still passes;
  - a fixture module's extension points render;
  - an `AuditableCommand` writes one audit row with the actor;
  - `make admin-build`, `make admin-test`, `make admin-lint` run locally and in CI;
  - the shell matches the design reference at 1440 px and works at 390 px;
  - ADM-8's shared-JS budget is met.

**Outcome (2026-10-30).** Done in commit `admin-foundation`, rebased onto #9, #10, #14 and #31. It ran on lane D (8084/5436/8029), not lane B. Everything is under `/admin`; the public site is unchanged and still starts no session. Read `CLAUDE.md` `### Admin` before writing back-office code.

- **Not checked by a person.** These gates ran only in headless Chromium, with scripted results: the design comparison at 1440 px, usability at 390 px, and the keyboard walkthrough (no screen reader used). A real authenticator app was never tried; codes were computed by script. The PNGs were not compared with the live page. All of these still need a human.
- **Packages:** Composer `symfony/security-bundle 7.4.*`, `scheb/2fa-bundle ^8.6`, `scheb/2fa-totp ^8.6`, `bacon/bacon-qr-code ^3.1` (`symfony/rate-limiter` came with #8). npm in `admin-ui/`: `vue`; dev `vite`, `@vitejs/plugin-vue`, `vitest`, `@vue/test-utils`, `jsdom`, `eslint`, `@eslint/js`, `eslint-plugin-vue`, `globals`. Compose: a `node:26-alpine` service in the `tools` profile.
- **Modules:** `Admin`, `Cms` and `Crm` are in `ArchitectureRules::MODULES`, `ModuleBoundariesTest` and every public module's deny-list (S2: Admin → Shared; Cms → Admin, Venues, Geo, Media; Crm → Admin, Venues, Geo, Inquiries). `ModuleBoundariesProbeTest` proves Cms → Crm fails. `src/Cms` and `src/Crm` hold only `.gitkeep`. New layers: `Extension`, `Security`, `Messenger`.
- **Tables and migration:** `Version20261030152714` creates `admin_users` (e-mail stored lower-case and unique, `role` admin|editor, `totp_secret_encrypted` NULL until enrolled, `is_active`, `last_login_at`; users are deactivated, never deleted) and `admin_audit_log` (append-only, no `updated_at`; `actor_type` admin_user|console|system, `actor_id` set only for admin_user, `command`, `subject_type`, `subject_id`, `changes` JSONB). Mapping `orm.mappings.Admin`. `AdminRole` and `AuditActorType` are pinned in `VocabularyChecksTest`.
- **Routes** (15 routes, 19 method/path pairs, all `unlocalized`):
  - `admin_dashboard` `GET /admin`, `admin_login` `GET|POST /admin/login`, `admin_2fa_login` `GET /admin/2fa`, `admin_2fa_check` `POST /admin/2fa/check`, `admin_totp_enrol` `GET|POST /admin/2fa/einrichten`, `_logout_admin` `POST /admin/logout`.
  - `admin_users` `GET /admin/benutzer`, `admin_users_new` `GET|POST …/neu`, `admin_users_edit` `GET|POST …/{id}`, and `POST` `admin_users_password` `…/{id}/passwort`, `admin_users_deactivate` `…/{id}/deaktivieren`, `admin_users_reactivate` `…/{id}/reaktivieren`, `admin_users_reset_totp` `…/{id}/2fa-zuruecksetzen`. All `ROLE_ADMIN`.
  - `admin_audit_log` `GET /admin/audit-log` (`ROLE_ADMIN`; filters `actor`, `subject_type`, `subject_id`, `from`, `to`; a page past the end shows the last page; invalid filters answer 422 with the unfiltered list), `admin_styleguide` `GET /admin/_styleguide` (dev and test, `ROLE_EDITOR`).
- **Classes:**
  - Entities and queries: `App\Admin\Entity\{AdminUser, AuditLogEntry}`, `App\Admin\Repository\AdminUserRepository`, `App\Admin\ReadModel\{AdminUserListQuery, AdminUserRow, AuditLogQuery, AuditLogFilter, AuditLogPage, AuditLogRow}`. `AuditLogQuery::forSubject($type, $id, $limit)` serves "Zuletzt bearbeitet von" and the CRM timeline.
  - Commands, each with a handler in `App\Admin\CommandHandler`: `App\Admin\Command\{CreateAdminUser, ChangeAdminRole, DeactivateAdminUser, ReactivateAdminUser, ResetAdminPassword, ResetAdminTotp, EnrolAdminTotp, RecordAdminLogin}`. Business errors: `App\Admin\Exception\AdminUserRuleViolation`.
  - Security: `App\Admin\Security\{AdminArea, AdminIdentity, AdminUserProvider, AdminEntryPoint, AdminAccessDeniedHandler, AdminPasswordHasher, DevAutoLoginAuthenticator, TotpFailureHandler, TotpQrCode, TotpSecretCipher}`. **Anything that asks "is this the back office?" uses `AdminArea::contains()` or `isJson()`, never the raw path:** the router and the firewall decode the path, so `/%61dmin/benutzer` is `/admin/benutzer` to them.
  - Listeners: `App\Admin\EventListener\{AdminResponseHeadersListener, IdleTimeoutListener, RecordAdminLoginListener, RefuseInactiveAdminListener, RequireTotpEnrolmentListener, TotpThrottleListener}`.
  - Audit: `App\Shared\Messenger\{AuditableCommand, AuditSubject}`, `App\Admin\Messenger\AuditLogMiddleware`. The `command.bus` order is `[ResetClosedEntityManagerMiddleware, doctrine_transaction, AuditLogMiddleware]`, so the row commits or rolls back with the change.
  - Other: `App\Admin\ValueObject\{AdminRole, AuditActorType, ValidationErrors, NavigationItemView, NavigationSectionView, RecordTabView, RecordPanelView}`, `App\Admin\Controller\{DashboardController, SecurityController, TotpEnrolmentController, UsersController, AuditLogController, StyleguideController}`, `App\Factory\AdminUserFactory`, `App\Story\AdminStory`.
- **Twig functions:** `admin_navigation()`, `admin_dashboard_widgets()`, `admin_record_tabs(type, id)`, `admin_record_panels(type, id)`, `admin_icon(name, label)`, `admin_island(name, props)`, `admin_island_assets(nonce = null)`.
- **Catalogue domains:** `admin` (filled); `cms` and `crm` (empty stubs for #24 and #27).
- **Console:** `app:admin:create-user <email> --role=admin|editor [--name=…]`, `app:admin:reset-2fa <email>`, `app:admin:reset-password <email>`.
- **Make targets:** new `make admin-install`, `admin-build`, `admin-watch`, `admin-test`, `admin-lint`; `make lighthouse URL=/admin/… ADMIN=1` and `make validate-html URL=/admin/… ADMIN=1` log in through `DevAutoLoginAuthenticator` (dev only). `make init` also installs and builds the islands, `make test` builds them and runs Vitest, `make test-short` builds them, `make lint` includes `admin-lint`. CI added one step, `make admin-install`.
- **Extension points** (`App\Admin\Extension`; implement one in your module's `Extension` layer and autoconfiguration registers it; `requiredRole()` filters, `priority()` sorts descending):
  - `NavigationItem` (tag `admin.navigation_item`; `NavigationSection` overview|cms|crm|settings), `DashboardWidget` (`admin.dashboard_widget`; `WidgetSize` small|medium|wide), `RecordTab` (`admin.record_tab`), `RecordPanel` (`admin.record_panel`), `SearchProvider` (`admin.search_provider`, returns `SearchResult`; the interface exists, its registry and endpoint are #23's).
  - Registries `NavigationRegistry`, `DashboardWidgetRegistry`, `RecordTabRegistry`, `RecordPanelRegistry`, helper `PriorityOrder`. `tests/Fixtures/Admin/FakeModule/` shows each one.
  - Other slots: `AuditableCommand` for any module; the shell blocks of `templates/admin/base.html.twig` (`title`, `body_class`, `breadcrumbs`, `page_actions`, `page_header`, `main`, `aside`, `scripts`); `templates/admin/layout/_search_trigger.html.twig`, included with `ignore_missing` and not created yet; one `@use` per partial in `assets/styles/admin.scss`; one line per island in `admin-ui/src/islands.js`.
- **JSON endpoint convention:** `/admin/api/<module>/…`, route names `admin_api_<module>_…`, class-level `#[IsGranted]` and `#[IsCsrfTokenValid('admin_api', tokenKey: 'X-CSRF-Token', tokenSource: IsCsrfTokenValid::SOURCE_HEADER, methods: [...])]`; the token is in `<meta name="csrf-token">`. Validation errors answer 422 with `ValidationErrors::fromViolations()` or `fromForm()`, as `{"errors": {"field": ["message"]}}`. 401 and 403 are JSON too. A test fails any `/admin/api` controller without the attribute. No real `/admin/api` route exists yet; the convention is proved on the fake module.
- **How the next cycles use this:**
  - **#23:** create `_search_trigger.html.twig` and the palette; add the provider registry and `/admin/api/search`; add the "Neu" and bell buttons; build components on #22's classes (`assets/styles/admin/_button`, `_card`, `_table`, `_field`, `_pill`; refine them, do not fork them); add `admin/form/theme.html.twig`, which takes over from `admin/form/_field.html.twig`; add sections to `/admin/_styleguide`.
  - **#24:** add `App\Cms\Extension` classes for the navigation, dashboard widgets and the venue `SearchProvider`; render `admin_record_tabs('venue', id)` and `admin_record_panels('venue', id)` in the editor; make the `Venues` commands `AuditableCommand`s (Shared, so no dependency on Admin); teach the walker `{number}` (R2-m4); measure ADM-8's p95.
  - **#25:** add `RecordTab`s for Fotos, Räume, Dokumente and Bewertungen with `recordType()` `venue`; add its islands to `islands.js` (each ≤ 30 KB gzip); follow the JSON convention for `/admin/api/cms/venues/{number}/photos…`, where `{number}` also needs R2-m4.
- **Deviations from plan and research:**
  - Security: `RefuseInactiveAdminListener` on `CheckPassportEvent` replaces the user checker (scheb stops the event `checkPostAuth` runs on, and `checkPreAuth` would disclose a deactivated account). `_logout_admin` is declared POST-only by hand. `access_control` has four rules, because a lazy firewall checks access before logging out. `TotpFailureHandler` replaces P13's FAILURE listener. There is no COMPLETE listener (P7). The session holds fingerprints (P4). `AdminEntryPoint` answers a bad CSRF header under `/admin/api` with JSON 403.
  - Schema: `AdminUser` takes its id from the caller (P11, against `.claude/rules/database.md`, so `auditSubject()` knows it). The audit log has no `updated_at` (P12) and has an `actor_type` column (D17) as an enum. `app:admin:reset-password` is beyond S12's list (D13).
  - UI: an admin-only 700 font and a separate admin sprite (D22, D23). P19's base styles plus `admin/form/_field.html.twig`, because Symfony's default theme does not link errors to inputs. No search trigger, "Neu" or bell button until #23; logout is the sidebar icon button; the dashboard is an empty state. Audit rows stack by container query below 960 px, and `changes` shows in JSONB key order.
  - Beyond the plan: `AdminPasswordHasher`, `TotpQrCode`, `PriorityOrder`, `RecordTabView`, `RecordPanelView`, `AdminSchemaConstraintsTest`, `AdminTranslationKeysTest`. `IconSpriteTest` gained a `\b`, and `.claude/rules/php-architecture.md` was updated. The walker tries two encoded spellings per URL, for run time; the full list runs in the enrolment, idle, header and JSON tests.
  - Design reference: the 11 PNGs in `docs/reference/admin/` were rendered locally, not taken from the live page, using the artifact's `dc-runtime.js`. The 11 live source files were byte-identical to the local ones. `Login-totp.png` is the eleventh, because that step is reachable only by clicking.
  - CI: `.php-cs-fixer.dist.php` excludes `admin-ui/node_modules`. The npm package `flatted` ships a PHP file, so `make cs-check` failed on a fresh `make admin-install`. Locally `make cs-fix` had rewritten that git-ignored file without anyone noticing. Fixed in the follow-up commit `admin-foundation: fix CI`.
- **Measured** before the rebase, on lane D: shared JS 22,031 B gzip of 61,440, `HelloIsland` 303 B of 30,720, admin CSS 5,126 B of 30,720 (`AdminAssetBudgetTest`). Lighthouse Accessibility 100 on login, dashboard, users and the audit screen with rows; seo 50 because of `noindex`. vnu no errors on the same pages.
- **Carried forward, open:**
  - **R2-m1, invalid bytes give a 500.** `subject_id` or `subject_type` with `%ff` on the audit screen (`SQLSTATE[22021]`), and a NUL in free text on the JSONB audit row (`SQLSTATE[22P05]`; the transaction leaves no user). Admin-only, nothing is written, but every later `AuditableCommand` with free text inherits the NUL case. Fix: reject them on the form, or normalise in `AuditLogMiddleware`.
  - **R2-m2, the admin walk accepts a 500 as "reached".** `assertNotTurnedAway()` in `ChecksAdminRoutes.php:347` must reject status ≥ 500; until then `test_an_admin_reaches_every_route` does not prove its name.
  - **R2-m3, very long unbroken values scroll the audit table** at 1280 px and wider (`assets/styles/admin/_audit.scss`). Likelier once CMS summaries carry URLs or slugs. Fix: `overflow-wrap: anywhere` on the wide cells with `table-layout: fixed` or a column minimum.
  - **R2-m4, the walker fills every route parameter with a UUID.** #24's planned `/admin/locations/{number}` and #25's routes need handling if they require digits: the route answers 404 and the walk fails loudly until the value is taught in `ChecksAdminRoutes::adminEndpoints()`. Deriving the sample from the route's requirement is the fix.
  - **#23, #25: a JSON route answers 302 during the 2FA code step**, not JSON. It can happen when a second tab restarts the login while an island is open. Treat a redirect or a non-JSON answer as "session gone".
  - **#23:** the stacked audit view could use real label elements; today the labels are `::before` content. Chromium exposes them, VoiceOver is untested.
  - **Walker runtime** is about 0.8 s per method/path pair and grows linearly. Skipping `test_other_spellings_match_no_route` on the probe kernel saves 4.5 s with no lost coverage.
  - **Login limits:** a failed CSRF check on the code form counts as a wrong code, and each code attempt uses up `login_throttling`, so after five failures the right password shows the throttle message too. Both fail closed.
  - **Unknown-e-mail timing differs** (55 vs 434 ms, wrong password on an unknown vs an existing account). This is Symfony's default, bounded by the throttle.
  - **ADM-8 p95 ≤ 300 ms is deferred to #24**, which has real data.
  - **D6, the first-login enrolment window:** someone holding only the password can enrol first, which locks the real user out until `app:admin:reset-2fa`, and the window reopens after every 2FA reset. The `EnrolAdminTotp` audit row is the trace. Hardening, if wanted: a time limit or a one-time link.
  - **#16:** CSP, `robots.txt Disallow: /admin`, pass the nonce to `admin_island_assets(nonce)`, error templates (`/admin` 403 and 404 are Symfony's English defaults in prod).
  - **#20:** a real `ADMIN_TOTP_KEY` (without one, production silently uses the dev key in `.env`), a persistent `var/sessions/<env>`, trusted proxies (throttling and `cookie_secure: auto` depend on them), `framework.trusted_hosts`, the `admin-ui` build in the deploy (`npm ci && npm run build`), and env defaults for `ADMIN_DEV_AUTOLOGIN*`, which `DevAutoLoginAuthenticator` requires.
  - **#27:** `AuditLogQuery::forSubject()` and `TimelineActivityType`.
  - **Smaller notes:** the last-admin rule has no row lock (`app:admin:create-user` recovers). `test_nothing_in_the_app_reads_the_raw_path` bans only `getPathInfo(` but scans public modules too, so a later public listener calling it fails an Admin test. The audit table shows English command names (an owner call).

### 23. `admin-ui-kit`

W5. Depends on #22.

- **Components** from the design reference, each as a Twig component in `templates/admin/components/` and, where an island needs it, a Vue component in `admin-ui/src/components/` that renders the **same markup and classes**: button (primary, secondary, ghost, danger, icon), badge/status pill, tier badge, avatar/initials, card, data table (sortable headers as links, row actions, selection, empty state, pagination), filter bar (search + selects as a GET form, active filter chips), tabs (record tabs), field group / property list (label + value, inline-edit affordance), modal (`<dialog>`), drawer, dropdown menu, toast, combobox (async options, keyboard), date input, money input (CHF), Markdown textarea with preview, file drop zone, sortable list (drag + move up/down buttons), kanban column + card, timeline item, empty state, skeleton.
- **Form theme** `templates/admin/form/theme.html.twig` for Symfony Forms: labels, help, errors linked by `aria-describedby`, required markers, a character counter for limited fields, the locale tabs of S12.
- **Global search** (`SearchProvider`, ⌘K / Ctrl+K command palette island) with a JSON endpoint `/admin/api/search?q=` that merges providers; #24 and #27 add providers.
- **Styleguide** at `/admin/_styleguide` (dev and test only, logged in), one section per component with every variant, like #1's.
- **Done when:**
  - the styleguide matches the design reference at 1440 px;
  - Twig and Vue variants of the same component render identical markup (a Vitest snapshot vs. the Twig output fixture for button, badge, table row and modal);
  - axe/Lighthouse Accessibility ≥ 95 on the styleguide;
  - keyboard walkthrough of combobox, modal, sortable list and command palette;
  - ADM-8 CSS budget.

**Outcome (2026-10-30).** Done in commit `admin-ui-kit`. No migration; Composer `league/commonmark ^2.10.2` (brings `league/config`, `nette/schema`, `nette/utils`, `dflydev/dot-access-data`); no npm additions. Every `/admin` page reads the Vite manifest, so a checkout or deploy needs `make admin-install admin-build` before the first admin request. Every component with every variant: `/admin/_styleguide` (`templates/admin/styleguide/_components.html.twig`, one `<section data-component>` each).

- **Twig kit** (`templates/admin/components/`; each file opens with a docblock of its params; strings arrive translated). Atoms are included `with_context = false`, shells embedded `… only`:
  - `_button` (`label`, `variant` primary|secondary|ghost|danger, `size` sm|xs|lg, `icon`, `icon_only` → `aria-label`, `href` → `<a>`, `type`, `attributes` map); `_pill` (`label`, `tone` green|grey|pink|blue|amber|red|dark, `dot`); `_tier_badge` (`tier` = a `Venues\ValueObject\Tier` value, `label`; a Twig hash, so Admin stays free of Venues, `TierBadgeTest` pins it to the enum); `_empty` (`icon`, `title`, `text`); `_filter_chip` (`label`, `remove_href`, `remove_label`).
  - `_tabs` (status tabs as links: `label`, `items` list<{label, href, count?, tone?, active?}>); `_record_tabs` (`label`, `tabs` from `admin_record_tabs()`, `current_route`); `_locale_tabs` (`id`, `label`, `locales` list<{code, label}>, `panels` map code → markup; a tablist from two locales via `js/admin/tabs.js`, a plain label for one, all panels without JS); `_pagination` (`page`, `last_page`, `total`, `per_page`, `label`, `previous_label`, `next_label`; numbers from `admin_page_links()`, URLs keep the query).
  - `_record_header` (`title`, `subtitle`; blocks `meta`, `actions`, `tabs`); `_filter_bar` (GET form with a visible "Filtern" button: `action`, `id`, `search_name`, `search_value`, `search_label`, `search_placeholder`, `submit_label`, `reset_href`, `reset_label`, `chips`; block `filters`); `_table` (`label`, `id`, `columns` list<{label, sort_key?}>, `sort`, `dir`, `select_label` (adds the selection column), `actions_label` (adds the actions column); blocks `rows`, `empty`, `foot`; a sort header is a link `sort=<key>&dir=asc|desc` with `aria-sort`); `_table_row` (`select_name`, `select_value`, `select_label`, `actions_column`; blocks `cells`, `actions`); `_dropdown` (a `popover`, not `role="menu"`: `id`, `label`, `icon`, `icon_only`, `variant`; block `items`); `_card` (`title`, `id`; blocks `actions`, `body`, `foot`); `_modal`/`_drawer` (`<dialog data-dialog>`: `id`, `title`, `close_label`, `danger`; blocks `body`, `footer`; a destructive confirm gives Cancel `autofocus` through `_button`'s `attributes`).
  - Also `_avatar`, `_skeleton`, `_kpi`, `_progress` (native `<progress>`), `_property`, `_chip_check`, `_seg`, `_timeline_item`, `_kanban_column`, `_kanban_card`, `_dropzone` (a real file input over the zone), `_sortable` (POST move-up/down forms, `aria-disabled` at the ends), `_required_hint`.
  - **`_scripts.html.twig`**, included by `templates/admin/base.html.twig`, loads `js/dialog.js` and `js/admin/{palette-trigger,tabs,dropdown,char-counter,markdown-preview}.js` on every back-office page. **A new admin script goes there** (and is then counted by `AdminAssetBudgetTest`'s glob of `assets/js/admin/*.js`); the scripts delegate at document level, so they work on island markup too. They load from `'self'` and need no nonce under #16's policy.
  - Styles: one `assets/styles/admin/_<component>.scss` each, appended to `admin.scss`; input borders use the new token `--a-field-strong` (≥ 3:1, `AdminInputContrastTest`). Seven icons added to `assets/icons/admin-sprite.svg` (21 → 28).
- **Form theme** `templates/admin/form/theme.html.twig`, opted in per template with `{% form_theme form 'admin/form/theme.html.twig' %}` (never `twig.form_themes`, so the public inquiry form cannot inherit it; `FormThemeTest`). `aria-describedby` joins help, counter, error; `required` shows `*` (add `_required_hint`); `attr: {'data-counter-max': n}` adds a counter; money fields need `html5: true` and show `.field__addon` CHF. #22's `templates/admin/form/_field.html.twig` is deleted; users, audit and TOTP screens use the theme. Form types `App\Admin\Form\ComboboxType` (`url`, `static_options`, `display_value`, `free_text`; select mode submits a hidden value, free-text mode submits the visible input as typed; the plain input is the island's fallback, so it works before the mount and without JS; a `url` answers `{"options": [{"value", "label", "detail"}]}`) and `App\Admin\Form\MarkdownType` (Schreiben/Vorschau tabs).
- **Twig functions:** `admin_query_path(changes)` and `admin_page_links(page, last)` (`App\Admin\Twig\AdminUrlExtension`; the current route and query of the main request with `changes` merged, null removes a key, `page` dropped unless named). `App\Admin\Twig\IslandExtension`: `admin_island(name, props, fallback = '')` (the fallback sits unescaped in the mount point) and new `admin_island_deferred(name, props)` (`data-island-defer`, loads nothing until first use).
- **Vue kit** (`admin-ui/src/`, imported only by islands): `components/Ui{Icon,Button,Pill,Avatar,Empty,Skeleton,TableRow,Modal,Drawer,KanbanColumn,KanbanCard,TimelineItem,Dropzone,SortableList,Combobox}.vue` with the Twig twins' markup; `composables/useToast.js`, `useListbox.js`; `api.js` with `adminFetch(url, {method, body, signal})` (a redirect, 401 or 2xx non-JSON throws `SessionGone`; 204 gives `null`; 422 JSON throws `ValidationFailed` with `.errors`; any other status `RequestFailed` with `.status`). Islands: `command-palette`, `combobox`, `styleguide-sortable`. Twig/Vue parity for button, pill, table row (with and without actions) and modal is pinned by `admin-ui/tests/fixtures/markup/*.html`, checked by `ComponentMarkupFixturesTest` and `markup-parity.test.js`.
- **Global search:** `App\Admin\Extension\SearchProvider` now also has `label(): TranslatableInterface`, `priority()`, `requiredRole()`. `App\Admin\Extension\SearchProviderRegistry::search($q)` asks each visible provider for ≤ 5 results and drops empty groups (`App\Admin\ValueObject\SearchGroup`). `admin_api_search` `GET /admin/api/search?q=` (`App\Admin\Controller\SearchController`, editor, no CSRF on a read) → `{"groups": [{"label", "results": [{"type", "label", "detail", "url"}]}]}`; `q` trimmed, cut to 100 characters, fewer than 2 gives no groups. The sidebar slot `templates/admin/layout/_search_trigger.html.twig` holds the button and the deferred palette; `palette-trigger.js` imports the entry on first click or ⌘K/Ctrl+K and dispatches `admin:mount-islands`. #24 and #27 add providers.
- **Markdown:** `App\Shared\Markdown\MarkdownRenderer::render()` (league/commonmark, core only, raw HTML escaped, unsafe links dropped, nesting and delimiter limits) behind `admin_api_markdown_preview` `POST /admin/api/markdown-preview` (`App\Admin\Controller\MarkdownPreviewController`, `{"text"}` ≤ 20000 → `{"html"}`, a null or non-string text is a 422). Both endpoints depart from `/admin/api/<module>/…` because they belong to no module.
- **Other:** `PageLinks` moved to `App\Shared\ValueObject\PageLinks` (Search's listing uses it from there). The route walker (`tests/Application/Admin/ChecksAdminRoutes.php`) now accepts GET JSON routes (a read answers 200, not the 403 a write without token gets). The kit's strings are an appended `ui:` block in the `admin` catalogue (`ui.<component>.<name>`). `PublicPagesLoadNoAdminAssetsTest` proves public pages load no admin asset.
- **Budgets (ADM-8, `AdminAssetBudgetTest`, gzip -9):** shared JS 29.3 KB of 60 (entry closure 24.9 KB + vanilla scripts 4.4 KB; a chunk shared between islands now counts against each), largest island 3.5 KB of 30 (command palette), admin CSS 9.3 KB of 30. Lighthouse Accessibility 100 on the styleguide; vnu no errors.
- **Deviations:**
  - Design: darker input borders (`--a-field-strong`, WCAG 1.4.11), `*` required marker plus a hint line, an explicit "Filtern" button, 24 px chip remove button, no bell, global "Neu" moves to #24; modal, drawer, dropdown, skeleton, Markdown and money input are not in the design and were drawn from the tokens.
  - Design decisions: an `admin:mount-islands` event replaces an entry export (Vite drops entry exports); the combobox has two stated modes; `adminFetch`'s status rules (a 413/500/504 never reports a lost session); the audit screen keeps its prev/next markup (only its URLs moved to `admin_query_path()`); `palette-trigger.js`'s dynamic `import()` is the one exception in `.claude/rules/frontend.md`.
  - Implementation: `_button` builds its attributes from a hash (a `{% set %}` capture tripped `TemplatesHaveNoRawTextTest`); an extra `StyleguideSortableIsland`, because a `Ui*` component reaches a page only through an island (1.7 KB); the `IslandExtension` tests sit in `tests/Unit/Admin/IslandManifestTest.php` (shared fixture manifest); `_table`'s planned `selectable` is `select_label` on the table and `select_name`/`select_value`/`select_label` on the row (F-m7); the browser walk used headless Chromium with #22's dev-login header instead of Claude in Chrome (accepted: the header is inert outside dev and skips only password and TOTP).
  - Fixed by the finisher from the review: Esc in the palette now resets the query and aborts a pending fetch (F-m1); `{"text": null}` answers 422 (F-m2); `_table_row` takes `actions_column: true` so a row without actions keeps its cell (F-m3, also `UiTableRow`'s `actionsColumn`); `CLAUDE.md` no longer describes `_field.html.twig` (F-m5).
- **CSP (Case A, with #16's real header):** passed by the finisher on the rebased branch (lane C, dev). `/admin/_styleguide` sends #16's policy with a per-request nonce, and `<script src="/admin-build/…/main-*.js">` carries the same nonce; the kit's `_scripts.html.twig` files load from `'self'`. The headless walk against that real header (no injecting proxy, so not the walk script's `csp` mode, which overwrites the header) passed 41 of 41 with zero `securitypolicyviolation` events, on `/admin` and the styleguide: palette (click, Ctrl+K, first use), modal, drawer, combobox, sortable list, locale tabs, Markdown preview, drop zone, dropdown. In dev the web profiler adds its own nonces and `'unsafe-inline'` to the header, which browsers ignore once a nonce is present.
- **Carried forward:**
  - **#24:** `_record_header` has no thumbnail slot (cms-venues-plan Step 8.5): use `meta` or append a `media` block. `_locale_tabs` always opens the first locale, so a 422 in another locale's panel starts hidden; add an active-panel parameter. Each `_table_row` in a table with `actions_label` passes `actions_column: true`. The walker still fills `{number}` with a UUID (`ChecksAdminRoutes.php`); teaching it is #24's Step 5.3. `unsaved-cards.js` goes into `_scripts.html.twig`, or `AdminAssetBudgetTest` fails. A combobox inside a `<dialog>`: Esc also closes the dialog (`useListbox` does not `preventDefault()`). #24's overview row omits #21, but its optional address needs #21's migration. #24 supplies `admin_api_cms_towns` (GET, `{"options": […]}`).
  - **#25:** `adminFetch` and its errors, `UiDropzone`, `UiSortableList`, `UiModal`, `UiDrawer`, `useToast`, `_sortable`, `_dropzone`, `_progress`; a `blob:` thumbnail needs a CSP change.
  - **Weak tests (F-m6, behaviour correct):** `UiSortableList.test.js`'s focus test passes without the refocus code (move `up-b` instead); `MarkdownRendererTest`'s hostile-input case does not prove the nesting limits (assert ≤ 21 `<blockquote>`); `AdminUrlExtensionTest` pushes one request, so it cannot tell `getMainRequest()` from `getCurrentRequest()`.
  - Lower-confidence notes in the review: a failed lazy palette chunk leaves the palette dead until reload; `UiSortableList` lacks `dataTransfer.setData` (Firefox drag); `assets/js/admin/*.js` is outside ESLint.
  - **Pending human:** the styleguide against `docs/reference/admin/` at 1440 and 390 px and the keyboard walk of combobox, modal, sortable list and palette in real Chrome; VoiceOver on the palette and combobox.

### 24. `cms-venues`

W6. Depends on #8 (preview and links), #9 (Venues write side), #21 (the optional address), #31 (the S14 fields), #23. Runs in parallel with #25.

- **Screens:** the venue list (CMS-1) and the venue editor (CMS-2) at `/admin/locations/{number}`, built from #23's components. The editor has one form per section (Stammdaten, Texte, Adresse & Lage, Merkmale, Hochzeit, Kontakt, Häufige Fragen, Bewertung), each saving on its own (POST → redirect → GET), plus `RecordTab` and `RecordPanel` slots. #25 fills the Fotos, Räume, Dokumente and Bewertungen tabs, and #27 the CRM panel.
- **Write side:** fine-grained `Venues` commands that implement `AuditableCommand`, e.g. `CreateVenue`, `RenameVenue` (slug + history, CMS-4), `ChangeVenueTexts`, `RelocateVenue`, `ChangeVenueTerms`, `ChangeVenueWeddingFacts`, `ChangeVenueContact`, `AnswerVenueFaq`, `RateVenue`, `ChangeVenueTier`, `PublishVenue`/`UnpublishVenue` (publishing rules of CMS-3), `DeleteVenue`. Reuse #9's handler logic; do not duplicate it. Every handler sets `venues.cms_edited_at` (new column, this cycle's migration) when the actor is a staff user, and purges (CMS-10).
- **Import guard** (CMS-11, S8): #9's import skips and reports venues with `cms_edited_at` > their last import, unless `--overwrite-cms-edits`. This changes #9's handler, which is done by then.
- **Preview** (CMS-9): a `Cms` controller at `/admin/locations/{number}/vorschau` renders #8's detail template from #8's `VenueDetailQuery` with an "include unpublished" option, plus a banner. The public detail route stays untouched, because the admin session is invisible outside `^/admin`.
- **Global search:** a `SearchProvider` for venues (name, number, town).
- **Dashboard widgets:** "Unvollständige Locations" and "Zuletzt bearbeitet".
- **Done when:**
  - application tests cover the list filters, each editor section's success and error paths (one per #4 CHECK, in the form layer), stale-edit rejection, create → publish (with and without the publishing preconditions), tier change, slug change with a working 301, delete rules per role, the preview of an unpublished venue (staff only; the public URL still answers 410/404) and the purge calls;
  - an integration test proves the import guard;
  - the screenshot comparison with the design reference passes;
  - keyboard walkthrough of the editor.

### 25. `cms-venue-media`

W6. Depends on #9 (photo pipeline) and #23. Runs in parallel with #24 through the `RecordTab` extension point; its tests create venues with factories and open its own routes (`/admin/locations/{number}/fotos`, `/raeume`, `/dokumente`, `/bewertungen`).

- **Photos** (CMS-6): the photo-manager island: a drop zone with sequential uploads and progress, a grid in position order with the first photo marked, drag-and-drop reorder plus keyboard buttons, alt text per locale, room assignment and delete, all through `/admin/api/cms/venues/{number}/photos…`. Commands `AddVenuePhoto` (stage → generate → `VenuePhoto` → `copyVariants()`, as #9), `ReorderVenuePhotos`, `DescribeVenuePhoto`, `MoveVenuePhotoToRoom`, `RemoveVenuePhoto` (deletes its files). Errors from Media (`UnreadableImage`, a too-large file) become 422s with a German message.
- **Rooms** (CMS-5): the room-editor island (list + edit drawer, reorder, delete) with `AddRoom`, `ChangeRoom`, `ReorderRooms`, `RemoveRoom`. Each recomputes the venue's room facts.
- **Documents** (CMS-7): a server page (upload form, title per locale, reorder, delete) with `AddVenueDocument` (`publishDocument()`), `RetitleVenueDocument`, `ReorderVenueDocuments`, `RemoveVenueDocument`.
- **Reviews and rating highlights** (CMS-8): a server page with `AddVenueReview`, `ChangeVenueReview`, `RemoveVenueReview`.
- Every command is auditable, sets `cms_edited_at` (the column #24 adds; whichever of #24/#25 lands second rebases onto it, so #25 writes it through the `Venue` method #24 adds, or adds that method itself if it lands first, recorded in the Outcome) and purges.
- **Done when:**
  - integration tests cover each command (files created and removed, positions contiguous, room facts recomputed, a replaced photo gets a new id);
  - application tests cover the JSON endpoints (success, 422, CSRF, role) and the server pages;
  - Vitest covers upload queueing, reorder by keyboard and error display;
  - a manual browser check uploads five photos, reorders them, and sees the new first photo on the listing card after the purge (recorded in the review);
  - ADM-8 island budget.

### 26. `cms-pages`

W7. Depends on #17 (landing pages) and #23. Runs in parallel with #19.

- **Landing pages** (CMS-12): a list of #17's landing pages (canton, town, building type, with venue counts and whether they pass #17's threshold) and an editor for the intro text, SEO title and meta description per locale, with live length counters (#16's ≤ 60 / ≤ 155) and a search-result preview. Stored in #17's `landing_pages` (new columns) or in `content_blocks` keyed by landing page, whichever #17 left; the public page falls back to #17's catalogue template when nothing is set.
- **Text blocks:** `content_blocks` (`key`, `locale`, `body_markdown`, `updated_at`, unique `(key, locale)`). A module declares a block (key, label, where it appears, max length, default from a catalogue) through a `ContentBlockDefinition` extension, and renders it with `{{ content_block('key') }}`, Markdown rendered safely (`league/commonmark` with HTML disabled and safe links; research confirms). Initial blocks: the listing intro under the h1 (#7) and a footer note. Saving purges the pages that show the block.
- **Done when:**
  - application tests cover the editors, the fallback, safe Markdown (a `<script>` and a `javascript:` link are neutralised), the purge, and that public pages read blocks without starting a session;
  - the screenshot comparison passes.

## Back office: CRM (W9–W10, after launch)

The CRM starts after #20 (Q8). Read S12 and S13 first. Its cycles deploy through #20's workflow, and every migration must be safe on production data (additive, no table rewrites).

### 27. `crm-foundation`

W9. Depends on #20 (live), #24 (the venue editor's panel slot and the venue `SearchProvider` pattern).

- **Schema** (S13): `crm_companies`, `crm_contacts`, `crm_company_venues` (`company_id`, `venue_id` unique, so a venue belongs to at most one company), `crm_activity_types` (seeded rows), `crm_activities`, `crm_activity_companies`, `crm_activity_contacts`, `crm_tasks`. Vocabularies per S13. Entities in `Crm\Entity`, read models in `Crm\ReadModel`.
- **Screens:** company and contact lists (CRM-6), record pages (CRM-3) with inline property editing (one small JSON write per field, through commands), the timeline composer island (CRM-4), and tasks (CRM-5) with the task list, record task panels and dashboard widgets ("Meine Aufgaben", "Überfällig"). The timeline renderer registry `admin-ui/src/timeline/types.js` (hotspot, one line per type) and the `TimelineActivityType` extension point. The audit-log events of a company's venues are shown read-only.
- **Bootstrap** (CRM-1): `app:crm:bootstrap` + the button on the company list.
- **Venue panel:** a `RecordPanel` on the CMS venue editor shows the company, its contacts and its open tasks, and offers "Firma anlegen" when there is none.
- **Global search:** `SearchProvider`s for companies and contacts.
- **Privacy** (CRM-11): contact export and erasure (`admin`).
- **Done when:**
  - application tests cover the lists (search, filters, sort, CSV export per role), the record pages, inline edits (success, 422, stale edit), timeline entries (each type; edit and delete only your own), tasks, bootstrap idempotence, the venue panel, export and erasure;
  - Vitest covers the composer and the inline editor;
  - the screenshot comparison with the design reference passes;
  - on production, `app:crm:bootstrap` runs once and its counts are recorded in the Outcome.

### 28. `crm-deals`

W10. Depends on #27. Parallel with #29 and #30.

- **Schema:** `crm_deal_stages` (seeded: Neu, Kontaktiert, Angebot, Verhandlung, Gewonnen, Verloren; `position`, `probability`, `is_won`, `is_lost`), `crm_deals` (S13 fields; `tier` from `Venues\ValueObject\Tier`), `crm_deal_contacts`, `crm_activity_deals`, and the `deal_stage_changed` activity type.
- **Screens:** the pipeline board island (CRM-7, sums per column, filter by owner and close month) with a list view alternative, the deal record page, and deals on company and venue records (a `RecordTab` on the company and a line in #27's venue panel).
- **Won and renewals** (CRM-8): "Tier auf Location anwenden", the "Verlängerungen" widget, `app:crm:renewal-tasks` (cron, added to #20's crontab), "Verlängern". The company `stage` is now derived (S13).
- **Done when:**
  - application tests cover the deal CRUD, stage moves (JSON, CSRF, keyboard menu path), applying the tier (venue tier changed, audited, purged), renewal-task idempotence and the derived company stage;
  - Vitest covers the board's drag and the keyboard move;
  - the screenshot comparison passes.

### 29. `crm-inquiries`

W10. Depends on #14 and #27. Parallel with #28 and #30.

- **Ingestion** (CRM-9): a subscriber to #14's `InquirySubmitted` event creates or updates the `couple` contact and writes `crm_inquiry_links` (`inquiry_id` FK → `inquiries` ON DELETE CASCADE, `contact_id`, `activity_id`, `status`), plus the `inquiry` activity type and timeline entries on the contact and on the venue's company. A failure is logged and reported to Sentry, and never surfaces to the guest.
- **Inbox:** `/admin/crm/anfragen` with status, venue, guests, date and mail outcome; "Neue Anfragen" on the dashboard and as a sidebar badge.
- **Backfill:** `app:crm:backfill-inquiries` (idempotent), run once on production.
- **Retention** (CRM-11): `app:crm:purge` (cron) and its interplay with `app:inquiries:purge`.
- **Datenschutz:** add the CRM paragraph to #18's Datenschutz template (internal processing of inquiry data for customer care; retention) and list it in the Outcome for the owner's review.
- **Done when:**
  - integration tests cover ingestion (new contact, existing contact by case-insensitive e-mail, a venue without a company), that a failing CRM never fails the inquiry, the backfill idempotence and the retention;
  - application tests cover the inbox;
  - the backfill's counts on production are recorded in the Outcome.

### 30. `crm-email`

W10. Depends on #27 (and #20's mail setup for the production check). Parallel with #28 and #29.

- **Schema:** `crm_email_templates` (name, subject, Markdown body, merge fields), `crm_emails` (activity, to, cc, subject, body, Mailer message id, status `queued|sent|failed`, error), the `email_sent` activity type.
- **Composer island** (CRM-10) on contact and company records: template picker, merge-field preview, Markdown body, send. The handler renders HTML + text like #14's mails, sends synchronously (S1: no workers), records the status, and honours `CRM_MAIL_OVERRIDE` outside production. Templates are managed on a settings screen (`admin` only).
- **Done when:**
  - integration tests cover rendering with merge fields (missing values render empty, no Twig injection: templates are not Twig, merge fields are a fixed list), sending through the Mailer test transport, the override and a failed send;
  - application tests cover the endpoints and the template screen;
  - on production, a test e-mail to the owner arrives and passes SPF/DKIM/DMARC (recorded in the review).

## Out of scope / proposals (not scheduled)

- **Venue self-service portal:** venue operators edit their own listing, with an approval step (Q9 said staff only for now).
- **CRM extensions:** custom properties on companies, contacts and deals; inbox sync (IMAP/BCC address) to log replies; e-mail open/click tracking; reports beyond the dashboard widgets; invoicing and payment status; automatic tier downgrade when a contract ends (#28 only reminds).
- **Back office dark theme** and a mobile-first back office (S12 only requires that it stays usable at 390 px).
- **Map view ("Karte")** of the result list.
- **Bulk inquiry from the Merkliste** (eventlokale has it).
- **"Location eintragen" on the new site:** a public form that replaces the Wix form at `/listing`, with the lead landing in the CRM. It is a lead form, not self-service, so Q9 stands (Q14 decides when it is needed).
- **Facts for the other ≈640 venues:** a questionnaire mail to venues without owner data, sent through #30 and answered into the CMS (S14, **Coverage**).
- **Liechtenstein** as a region: a `li` row, its towns and the map (Q13).
- **A curated Ambiente vocabulary:** ~8 style codes (romantisch, modern, rustikal, …) as a filter, built from S14's 176 free texts.
- **Home page** with hero search.
- **French, Italian and English** (one cycle per language: catalogues, localized route paths, venue translations, hreflang). S4 makes these additive.
- **A TypeScript/JS framework layer** (only if a real need appears).
- **Analytics** (the owner decided on no consent banner, so any analytics must be cookieless).
- Ratings collected on the site (we display imported ratings only), provider listings, magazine, Award, packages.
