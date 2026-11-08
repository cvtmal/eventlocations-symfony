# Event-venue site as a fork of hochzeitslocation-symfony: what carries over

_Research only, 2026-11-06, at `main` 4652573. No code was changed. Sources: the repo (`CLAUDE.md`, `docs/product.md`, `docs/rpi-cycles.md` S1–S17 and Requirements, `.claude/rules/*.md`, `docs/operations.md`, `src/`, `migrations/`, `translations/`, `templates/`, `bin/`, `.github/workflows/`) and a survey of www.eventlokale.ch's public pages made on 2026-11-06 (§1). Guesses are marked **(guess)**._

## Summary

- **The owner's "99 %" is right for the machinery and wrong for the parts the three differences touch.** About 85–90 % of the code moves over in some form. Roughly **45 % reuses as is** (Shared, Geo, Media, Admin, Stats, Favorites, the frontend system, deploy/cron/backup/CI, the test harness), **40 % adapts** (Venues, Search, Seo, Inquiries, Cms, Portal, Crm, every public template and catalogue), **10–15 % is new** (event-type and room-layout search, the locale gaps below, the business-model pieces), and a small slice is **dropped** (the Wix/owner importers, the eventlokale crawler, the customer-sheet import, the wedding facts). Shares are by module weight, not counted lines **(estimate)**.
- **Event filters are mostly data, with one model change.** The schema already carries eventlokale's vocabulary: `suitable_for` holds 21 event types and rooms carry four seating layouts with min/max each (`migrations/Version20261030114442.php`). What is missing is searching on them: `suitable_for` is not a filter parameter, and the guest filter reads only `venues.capacity_max` in four wedding bands. eventlokale itself filters on event type and one guest number, with exactly our four layouts and no layout filter, so parity is S–M. "N people in layout X" would go beyond eventlokale; it needs a per-layout capacity the listing can query, the one real model change.
- **eventlokale is German only** (no `/fr`, no hreflang; only its Award pages are translated), so languages are where the new site would lead.
- **S4's "a language only adds catalogues and route paths" does not hold.** The foundation is good (localized routes, `*_translations` with fallback, per-locale tabs in CMS and Portal, parity tests), but seven read paths assume `de`: publishing needs a German row, slug resolution is strict per locale while cards link with the fallback slug (a French page would link to 404s), hreflang reuses one slug for every locale, sitemaps are German only, canton and town slugs are single-language, `/konto` is unlocalized, and the venue's inquiry mail goes out in the guest's language. One `i18n-core` cycle (L) must come before any content.
- **Business model:** today a tier is only a ranking plus a badge, sold through a CRM deal and invoiced outside the app; an inquiry is a free, single-venue e-mail. Other tiers are a migration-sized change, and a multi-venue request is M. Entitlements per tier, automatic expiry of paid entries, online payment, and quotes answered in `/konto` are each new builds.
- **Recommended fork:** clone with full history into a new repository, keep `hochzeitslocation-symfony` as a second remote for cherry-picks, and squash the migrations into one baseline in the first cycle (option A, §4).
- **Biggest risk:** content. hochzeitslocation launched on the owner's own Wix CSV. The new site has no venue source yet, eventlokale's data is not ours, and the facts say nothing is shared with hochzeitslocation (§6, Q1).

---

## 1. Feature map: eventlokale.ch beside hochzeitslocation.ch

**How the survey was done.** On 2026-11-06 a sub-agent read eventlokale's public pages with plain fetches; nothing was blocked and no form was submitted. The site is a Nuxt 2 app, so filter labels and form fields were read from the server-rendered state and the public JS bundles, not from screenshots.

Pages read: home, `/locations/hochzeitslocation`, `/locations/hochzeit/zuerich`, three venue pages (Stufenbau, Bar Rouge, Rooftop Talacker50), the Merkliste, the advertising pages `/site/extern/werbemoeglichkeiten_{eintraege,werbeformate,mailing}.html`, `/site/guest/entries/create`, Impressum/AGB, About us and `/login`. The rendered "weitere Filter" overlay, the map view and the Event Creator were not seen as UI.

**Key facts that shape this report:**

- **eventlokale is German only.** It has no language switcher, no hreflang, and `/fr` answers 404. Its FR/IT/EN pages exist only for the Swiss Location Award. The footer language links lead to its country sites, eventlokale.de and eventlokale.at. Taxonomy terms carry `name_fr/it/en`, mostly empty. Venue texts are German. **Languages are therefore where we would lead, not catch up.**
- **eventlokale's room layouts are exactly our four:** Bankett (sitzend), Seminar (sitzend), Apéro, Konzert, each as min–max per room, plus a "Ganze Location" pseudo-room. A seating layout is **not** a search filter there. The guest filter is one number (`personen=`) against the venue's range.
- **Its tiers carry entitlements:**

  | Tier | CHF / year | Photos | PDFs | Event types | Placement |
  |---|---|---|---|---|---|
  | Premium | 449 | 12 | 4 | 4 | Good |
  | Top Premium | 949 | Unlimited, videos too | | 6 | Top, panorama header |
  | Top Premium+ | 1,998 | | | 8 | First results page for 2 months, magazine tip |

  Below Premium are Grundeintrag and Basiseintrag; whether these are free is unknown. Subscriptions renew automatically and are paid in advance. On top come paid boosts per month (Location Boost 878, Interregio Boost 2,480, Location des Monats 690), newsletter ads to about 90,000 subscribers (780–4,880), ad tiles and job ads. There is no commission per inquiry.

| Feature | eventlokale.ch | hochzeitslocation.ch | Class |
|---|---|---|---|
| Home with search | "Ich suche eine… [Location / Catering / Technik / Agentur / …]" + Ort/PLZ | Hero search: Kanton, Location-Art, Anzahl Gäste (HP-2) | Different |
| Listing, tier ranking, shuffle | Paid tier first, shuffled per visit (`seedId` redirect) | Paid tier first, shuffled per **day**, cacheable, no seed in the URL (S5, S6) | Same idea, ours is cacheable |
| Facets as URL path segments in any order (`/locations/hochzeit/zuerich/personen=100`) | Every combination is a landing page with a generated title | Query string (S4); clean paths only for canton, town and building above a threshold (#17), other combinations `noindex` | Different by design (ours avoids thin pages) |
| Event-type filter (about 24 types, grouped Firmenevents / Privatanlässe) | Yes, first chip in the bar | Data exists (`suitable_for`, 21 codes), not a filter | **Missing on our side** (data ready) |
| Region: canton, town, Ort/PLZ + radius, canton map | Yes, plus DE/AT states | Yes (FI-1, FI-2), canton SVG map | Same |
| Guests | One number vs the venue's range | Four wedding bands on `capacity_max` (#51) | Different |
| Lage, Erreichbarkeit, Gebäudeart (39), Infrastruktur, Technik, Mobiliar, Umgebung, rooms/area/height | Yes | Yes, copied 1:1 in #4/#12 (FI-4) | Same |
| Soft "Preis/Stil/Betrieb" adjectives (einfach, edel, …) | Facets | None; we have `price_level` (moderate/medium/exclusive) from the owner data | Different |
| Wedding facts (Trauung, Sperrstunde, Nutzung, Preisniveau) | None | FI-7, DP-17 (S14) | Ours only, wedding-specific |
| Map view of results | "Karte" toggle | Only the near-me page with Google Maps (#52) | **Missing** (partly) |
| Card | Photo slider, rating + score + likes, 3 highlights, guest range, town, tier badge | Photo carousel, tier badge, capacity, place, price/ambience tags; no rating since #53 | Mostly same |
| Detail page sections | Facts, rooms (photos, size, daylight, barrier-free, floor plan, 4 layouts), FAQ (parking, catering, barrier-free, daylight, hotel rooms), location + distances, contact with phone reveal, PDFs, comments, similar 12 | Same structure (DP-1–DP-16), plus a Google map and Google rating | Same |
| Ratings | Own score /10 from likes, comments and jury; 9 highlight categories; AggregateRating JSON-LD | Retired; Google Places rating (#53) | Different |
| Likes | Yes | No | Missing (minor) |
| Videos, 360° tours, price examples | In the model, empty on the venues checked | No | Missing (minor) |
| Inquiry form | Name, e-mail, phone, **company**, date, guests, event type, "Verfügbarkeit"/"Preis" checkboxes, prefilled message, Turnstile | Name, e-mail, phone, date, guests, occasion (wedding types), prefilled message; honeypot + fill time + limiter | Mostly same; company and the event type are missing |
| **Multi-venue inquiry** (up to 10 at once, from the Merkliste) | Yes | No (a proposal in `docs/rpi-cycles.md`) | **Missing** |
| **Event Creator** (beta: days, time slots, meals by level, overnights, exclusive use → priced packages, booking accepted/rejected, billing) | Yes, on venues that allow it | No | **Missing** (L+, see §3.3) |
| Merkliste without login | Yes, plus "Favoriten laden" / save | Yes (S7) | Same |
| Supplier directory (catering, technology, agency, furniture, infrastructure, entertainment, photo/video) | Yes, same listing and inquiry flow | No (out of scope) | Missing |
| Eventmagazin, SEO articles, job board, newsletter | Yes | No (out of scope) | Missing |
| Swiss Location Award | Yes, in 4 languages | No | Missing |
| Languages | German only (see above) | German only, built for more (S4) | Ours ahead once §3.2 is done |
| Venue-owner self-service | `/login` (provider back office with an inquiry archive, CSV, Pipedrive ids, from JS strings) | `/konto` (S16): claim, create, edit, review, statistics | Same idea; theirs shows inquiries |
| Paid packages, boosts, newsletter ads | Yes (above) | Five tiers sold through CRM deals, no entitlements, no boosts (S6, S13) | Different |
| Own statistics for venues | Not seen | Yes (S17) | Ours only |
| Back office CMS + CRM | Not visible (Pipedrive per the JS) | Own CMS + HubSpot-style CRM | Ours only |
| Performance, accessibility, no-JS filters | SPA | Server-rendered, Lighthouse ≥ 95, works without JS (S3, S11) | Ours only |

**Where our own content and brand are needed:** every text, photo, logo, icon and venue record. eventlokale's vocabulary lists (event types, building types, groups) are facts about how a market is cut, and #4 already took them from its public taxonomy endpoint. Their labels in our catalogues are our own wording; keep it that way and write fresh copy for the new site.

---

## 2. Reuse inventory

Classes: **R** reuse as is · **A** adapt · **N** build new · **D** drop. "Wedding baked in" lists where the wedding domain appears in each part.

### Cross-cutting

| Part | Class | Reason, files, what changes |
|---|---|---|
| **Architecture and conventions** (`src/<Module>/<Layer>`, phpat rules `tests/Architecture/`, CQRS `command.bus`, read models, validation strategy, `.claude/rules/{php-architecture,cqrs,read-model,validation,database,code-quality}.md`) | R | Domain-free. The namespace is `App\…`, so no namespace rename is needed. |
| **Shared building blocks** (`EntityId`, `DomainException`, `FlexibleDateTimeTzImmutableType`, `CleanText`, `ResetClosedEntityManagerMiddleware`, `ClientIpKey`, `SecurityHeadersListener`/`ContentSecurityPolicy`, `PublicCache`, `HttpCacheClearer`, `TrustedHostGuard`, `ChannelBindingMiddleware`, content blocks `Shared\ReadModel\ContentBlocks`, `MarkdownRenderer`, `EmailHtmlToTextConverter`) | R | Generic. Brand facts sit in `config/services.yaml` (`site.name: 'Hochzeitslocation'`, `site.contact_email`, `site.operator_*`) and `config/packages/framework.yaml:89` (`trusted_hosts`). |
| **Shared i18n** (`src/Shared/Translation/*`, `SwissFormatExtension`, `HreflangAlternates`) | A | See §3.2. `HreflangAlternates::forRequest()` reuses the matched route's parameters for every locale, so per-locale slugs get the wrong alternates. `SwissFormatExtension` builds `<lang>_CH`, so `fr_CH` groups with a narrow no-break space, not `'`. That is correct for French, but the tests pin only German. |
| **Legal pages** (`Shared\Controller\LegalController`, `templates/site/legal/*`, `shared` catalogue `legal.*`) | A | The structure is reusable. The text names the operator, Google Maps, the Merkliste cookie, the CRM, Sentry, Neon Frankfurt and the 12-month inquiry retention, and every sentence is a catalogue key. New domain and processors, plus a legal review in each language. |
| **Design system and frontend** (`assets/styles/**`, tokens `abstracts/_tokens.scss`, components `templates/components/*`, `assets/js/*`, Lucide sprite, budgets `AssetBudgetTest`, `ContrastTokensTest`, `PublicTokensDefinedTest`) | A | The mechanism is reusable, the look is not: S15's brand (Figtree, pink `#D1007A`/`#FF0096`, cream) and `assets/images/logo-hzl.png` are hochzeitslocation's. A new brand means new token values, logo, favicon, the boards in `docs/reference/public/` and a new `ContrastTokensTest` table. The JS modules (`overlay.js`, `live-count.js`, `overlay-form.js`, `carousel.js`, `merkliste.js`, `region-filter.js`, `share.js`, `near-me.js`, `stats.js`) are domain-free. The 6 KB group (`overlay.js + live-count.js + region-filter.js`) has 12 B left (`module-search.md`, #17), so new filter behaviour needs its own file. |
| **Mails** (`templates/emails/layout.html.twig`, `_blocks.html.twig`, `hl-` classes, `MailLiteralsTest`) | A | The layout and macros are reusable. The logo, the colours (`#d1007a`, `#f0f1eb`) and the texts change. The `hl-` prefix is internal and can stay. |
| **i18n catalogues** (`translations/*+intl-icu.de.yaml`, 3,500 lines) | A + N | German copy is rewritten for events: `search` has 96 wedding lines, `seo` 29, `venues` 9, `inquiries` 5, `messages` 4, `portal` 4, `shared` 5, `cms` 3, `favorites` 1. Then French (and Italian/English) catalogues are new: about 1,150 public lines plus `portal` (379) and `validators` (171). The staff domains (`admin`, `cms`, `crm`, 1,650 lines) can stay German (S12). |
| **Deploy, cron, backup, CI** (`bin/{build-release,deploy,deploy-gate,cron-*,prod-import,pull-media-backup,warm-cache}`, `.github/workflows/{ci,deploy,backup}.yaml`, `deploy/*`, `public/.htaccess`, `public/.user.ini`, `tests/Deploy/*`, `docs/operations.md`) | A | Proven on Hostpoint + Neon. Brand strings are in `bin/deploy` (`$HOME/www/hochzeitslocation.ch`), `deploy.yaml` (`SITE`, the smoke URL `/hochzeitslocations`, the www/http redirect checks), `public/.htaccess` (canonical host), `deploy/crontab.txt`, `deploy/authorized_keys.example`, the account user `djweddin` in `docs/operations.md`, `CheckProductionEnvCommand::SITE_URL`/`SENDER_SUFFIX`, `compose.perf.yaml`, `Makefile`. These are mechanical renames. `bin/prod-import` keeps its shape, but its subcommands `export`, `crm-bootstrap`, `customers*` serve the Wix/customer imports that are dropped. |
| **Docker, Makefile, tools** (`compose.yaml`, `docker/`, `Makefile`, Lighthouse, Nu checker, k6 `tests/Load/`, perf stack) | R | Only project and page names change (`tests/Load/pages.js` paths). |
| **Tests** (514 test classes; `tests/Support/*`, Foundry factories `src/Factory/*`, stories `src/Story/*`) | A | The harness and most tests are reusable. Every test that asserts wedding copy, paths (`/hochzeitslocations`), guest bands, wedding facts or the five tiers changes with its feature. `DemoStory` is a fictional generator, so it keeps working, but it seeds wedding facts (`createWeddingFacts()`) and `suitable_for` with `wedding` first. |
| **Admin back-office UI** (`admin-ui/` Vite + Vue islands, 46 files; `templates/admin/components/*`) | R | Domain-free kit. Islands with domain wording: the room editor (seating keys) and the pipeline board (stages are rows). |
| **RPI and Claude setup** (`.claude/rules/*.md` 371 KB, `.claude/skills/fix-phpstan`, `CLAUDE.md`, `docs/rpi-cycles.md` 3,130 lines, `.rpi-tracking/` 26 MB, git-ignored) | A / D | Keep the convention rules, `fix-phpstan` and the `CLAUDE.md` skeleton. The `module-*.md` files are cycle logs ("Built in #17 …") full of wedding history. Condense each into a "current state" note in the fork's first cycle, or leave them and let them drift wrong. Start a new `docs/rpi-cycles.md` that inherits S1–S3 and S5–S13, S16 and S17 by reference, with S4, S6, S14 and S15 rewritten. Do not copy `.rpi-tracking/`: it is git-ignored, so a clone does not carry it anyway. Auto-memory lives per project path (`~/.claudepj/projects/<path>/memory`), so the fork starts with an empty memory. The owner rules (no GDPR machinery, deploys owner-only, gh account) have to be restated. |

### Modules

| Module | Class | Reason, files, what changes · wedding baked in |
|---|---|---|
| **Geo** (cantons, towns, place lookup, distances, canton SVG map, Liechtenstein) | R, plus A for languages | Data and lookup are domain-free. For languages: `cantons.slug` and `towns.slug` are single German columns (`migrations/Version20261030105455.php:52,104`), town names are official only (S4's planned `town_names` exonym table was never built), and `ImportTownsHandler` slugs with `AsciiSlugger('de')`. "Genf"/"Genève" in the place search and French landing slugs need `canton_names`/`town_names` per locale. |
| **Media** (`ImageVariantGenerator`, `MediaPublisher`, `venue_picture()`) | R | Domain-free. |
| **Venues** (aggregate, rooms, photos, documents, detail page, import, Google rating) | A | The core aggregate fits events well: rooms with area, height, floor and four seating capacities; the `infrastructure`, `furniture`, `technology`, `surroundings`, `building`, `setting` groups; documents; FAQ `own_catering`/daylight/parking; access distances; the Google place rating. **Wedding baked in:** S14's facts (#31 `Version20261030160506`): columns `price_level`, `capacity_seated`, `capacity_standing`, `party`, `has_curfew`, `curfew_at`, `instagram`; `venue_translations.ambience/capacity_note/party_note/opening_hours`; groups `ceremony`, `amenity`, `dining`, `vendor`, `usage`, `language` (56 rows); `ChangeVenueWeddingFacts`, `DetailWedding`, `templates/venues/detail/_wedding.html.twig`, `ChangeVenueTermsHandler` ("the six wedding groups"); `building.wedding_venue`; route paths `/hochzeitslocation/{slug}`, `/…/telefon`. Keep `price_level`, `capacity_seated/standing`, `opening_hours`, `instagram` and parts of `amenity`/`dining`/`language` (they are useful for events) and drop `ceremony`, `vendor`, `usage`, `party`/curfew **(proposal)**. **Drop:** `WixExport`, `WixRichText`, `WixPhotoCache`, `Legacy*`, `Owner*` (the Wix CSV pipeline, S14/#21/#32), `VenueRatingHighlight`/`VenueReview` (retired, gated drop). **Change:** `Seating` enum + `rooms.capacity_<layout>_{min,max}` (§3.1), `Tier` (§3.3), publish predicate and slugs (§3.2). |
| **Search** (`SearchCriteria`, `ListingSql`, `ListingQuery`, `FilterDataQuery`, `LandingPages`/`LandingPaths`, home, near-me, similar) | A, plus N | The query engine, ordering, paging, live count, filter-data hiding rule and landing machinery are reusable. **Wedding baked in:** routes `/hochzeitslocations`, `/hochzeitslocations/{slug}`, `/…/{canton}/{town}`, `/hochzeitslocation-in-meiner-naehe`; `GuestBand` (50/100/150/151, #51); `SearchCriteria::FILTER_CODES` (the seven FI-7 amenity codes, `usage=exclusive`) and the params `ceremony[] amenity[] usage[] price[] curfew`; `search/filter/_more_wedding.html.twig`; `HomeCategory` (five wedding tiles); `landing.building.<code>.{slug,heading,intro}` × 39 with wedding wording; the 96 wedding lines in `search`. **New:** event-type and layout-capacity filters, event landing pages (§3.1). |
| **Favorites** (Merkliste cookie, toggle, sync, page) | R | Domain-free (S7). One wedding line in `favorites`. A "Sammelanfrage" from the Merkliste is new and belongs to Inquiries (§3.3). |
| **Inquiries** (form, row, double-submit lock, mails, retention, events) | A | Robust core: fingerprint and advisory lock, delivery claim, honeypot and fill-time check, limiter, `INQUIRY_RECIPIENT_OVERRIDE`, purge. **Wedding baked in:** `Occasion` (`wedding`, `civil_wedding`, `wedding_aperitif`, `engagement`, `other`, default `Wedding`), `InquiryData::$occasion = Occasion::Wedding`, route `/hochzeitslocation/{slug}/anfrage`, the "Liebes {Name}-Team" preview, mail texts, guest count 1–5,000. The event fields (company, layout, time, catering, budget) and the business-model changes are in §3.3. |
| **Seo** (meta, JSON-LD, sitemap, robots, `llms.txt`, `.md` venue pages, error pages) | A | `EventVenue` + `LocalBusiness` JSON-LD fits events better than weddings. **Wedding baked in:** 29 `seo` lines (title patterns "… für die Hochzeit mieten | Hochzeitslocation", meta descriptions, `llms.txt` headings `## Location-Arten`), route `/hochzeitslocation/{slug}.md`. **Languages:** `VenueSitemapQuery` and `PagesSitemap` emit the default locale only (`t.locale = :default_locale`), and hreflang is wrong for per-locale slugs (§3.2). |
| **Stats** (beacon, spool, ingest, rollup, figures in `/admin` and `/konto`) | R | Domain-free (S17). Inquiry counts per venue are already there, which a pay-per-lead model can build on. |
| **Admin** (staff login + TOTP, roles, audit, extension points, shell, UI kit, Vue loader) | R | Domain-free. Brand: `scheb_2fa.yaml` issuer "Hochzeitslocation Backoffice", 2 lines in `admin`. |
| **Cms** (venue editor, photos, rooms, documents, landing texts, text blocks, preview) | A | Already per locale: `VenueTextsType`, `LocaleTitlesType`, `RoomType`, `LandingTextsType` and `TextBlockType` loop over `EnabledLocale::all()`. **Wedding baked in:** the "Hochzeit" card (`Cms\Form\VenueWeddingType`/`VenueWeddingData`), 3 `cms` lines, the incompleteness filters. The room editor gains layouts (§3.1), and landing texts gain event pages. |
| **Crm** (Locations, contact card, deals, tasks, timeline, inquiry inbox, e-mail, renewals) | A | The B2B sales model (Location → deal → tier → renewal) carries over unchanged in shape. **Drop:** the eventlokale crawler (`EventlokaleClient` with `WEDDING_EVENT_ID = 370`, `EventlokaleCrawl`, `EventlokaleCsv`, `EventlokaleCache`, `app:crm:crawl-eventlokale`, #49: the permission covered the wedding list, as a private list) and the customer-sheet import (`CustomerImport`, `CustomerSheet`, `CustomerClassifier`, `CustomerRecordMapper`, #48: the owner's sheet). **Adapt:** deal tiers follow `Tier`; 6 `crm` lines about couples ("Paare"); `CrmMailer` sends in the default locale only, and `crm_email_templates` has one language. |
| **Portal** (`/konto`: register, claims, editor, wizard, review queue, invoice task) | A | Golden rules S16 fit. **Wedding baked in:** `LocationCard::Wedding` and `WizardStep::Wedding` (`hochzeit` step, `portal_location_wedding` POST `/hochzeit`), 4 `portal` lines. **Gaps:** every `/konto` route is `LocalizedRoutes::UNLOCALIZED` and the catalogue is German; `portal_users` has no locale, so mails are German; rooms and documents stay with staff (S16), but for event venues rooms are the core data. The business-model gaps are in §3.3. |
| **Demo data and importers** (`src/Story/*`, `src/Factory/*`, `docs/content-format.md` + schema) | A | The content format (`app:venues:import`, YAML per venue, translations per locale) is the bulk onboarding path, and it carries over. Its wedding fields (`price_level`, `party.*`, the six groups) follow §3.1. DemoStory changes wording and facts. |

### What "wedding" looks like in the code (rename checklist)

- **Route paths** (all localized `['de' => …]`): `/hochzeitslocations`, `/hochzeitslocations/{slug}`, `/hochzeitslocations/{canton}/{town}`, `/hochzeitslocation/{slug}`, `/…/telefon`, `/…/anfrage`, `/…/{slug}.md`, `/hochzeitslocation-in-meiner-naehe` (`src/Search/Controller/{ListingController,NearbyController}.php`, `src/Venues/Controller/*`, `src/Inquiries/Controller/SubmitInquiryController.php`, `src/Seo/Controller/VenueMarkdownController.php`). Route names (`listing`, `venue_show`, …) are neutral, so the change is one string per route.
- **Enums:** `Occasion` (Inquiries), `TermGroup::{Ceremony, Amenity, Dining, Vendor, Usage, Language}`, `PartyAllowed`, `PriceLevel` (neutral), `Tier` (wedding-flavoured names `beliebte_location`, `empfehlung`), `GuestBand`, `HomeCategory`, `LocationCard::Wedding`, `WizardStep::Wedding`.
- **Taxonomy rows** (migrations): `suitable_for.wedding` at position 1, `building.wedding_venue` (primary position 3), the 56 rows of #31 plus `language.ro` (#32).
- **Catalogue keys:** `search` `landing.*`, `home.*`, `filter.*` (96 lines); `seo` titles and descriptions (29); `venues` `detail.wedding.*`; `inquiries` `occasion.*`, `email.*`; `portal` `editor.wedding.*`/wizard; `messages` layout strings (4).
- **Config and ops:** `site.name`, `site.contact_email`, `trusted_hosts`, the 2FA issuer, `CheckProductionEnvCommand`, `bin/deploy`, `deploy.yaml`, `.htaccess`, `crontab.txt`, `rate_limiter.yaml` comments.
- **Demo data:** `DemoStory` (wedding facts, `suitable_for` first `wedding`), `CrmStory`, `PortalStory`.

---

## 3. The three differences in depth

### 3.1 Event filters

**Today's model.** Terms are rows of `taxonomy_terms (term_group, code, position, primary_position)` linked by `venue_terms (venue_id, term_group, term_code, is_main)` with a composite FK. There are 13 groups (`TermGroup`), and the CHECK `chk_taxonomy_terms_term_group` lists them, so a new group is a migration. Labels are `term.<group>.<code>` in `venues`. Search reads them through `SearchCriteria::PARAMS` (query param → group: `setting building infra furniture tech surroundings ceremony amenity usage`). `setting`/`building` are OR inside the group, the rest AND (`ListingSql`). `FilterDataQuery::terms()` shows only codes with data (the hiding rule). The overlays are `search/filter/_{region,guests,more}.html.twig`, and "weitere Filter" renders groups through `_more_terms.html.twig`.

**What is already there for events (copied from eventlokale's vocabulary in #4):**
- `suitable_for`: 21 codes (`wedding, family_celebration, birthday, club_event, party_celebration, class_reunion, private_event, meeting, conference, congress, corporate_event, general_assembly, apero, team_event, christmas_party, concert, theatre, party, sports_event, trade_fair, public_event`), stored with `is_main`, shown as "Geeignet für" on the detail page (`_facts.html.twig`), **but not a filter**: it is not in `SearchCriteria::PARAMS`.
- Rooms: `rooms.capacity_{seminar,banquet,apero,concert}_{min,max}` with CHECKs (`Version20261030114442.php:303-332`), `Seating` enum, `Room::offerSeating()`, the CMS room editor island and `DetailSeating` on the room dialog. `venues.room_count`, `largest_room_m2` and `max_room_height_m` are derived by `RoomSummary::apply()` and filter as `rooms`, `area`, `height`.
- `infrastructure`, `furniture`, `technology`, `surroundings`, `building` (39 codes incl. `seminar_room`, `meeting_room`, `congress_centre`, `exhibition_hall`): already filters in "weitere Filter" (FI-4).
- Catering: `venues.own_catering` (enum `OwnCatering`, an FAQ answer), plus `amenity.catering_available`, `amenity.external_caterers` and `amenity.own_kitchen`, and the `dining` group (wedding-specific in part: `wedding_cake`, `kids_menu`).

**What event search needs, and the size of each step:**

| Need | Kind | Size |
|---|---|---|
| **Event type filter** (`event[]=corporate_event`): add `suitable_for` to `SearchCriteria::PARAMS`, decide OR (like building) rather than AND, a control in the hero search and an overlay section, `FilterDataQuery` support | Data + filter UI (one hotspot line in the codec) | S–M |
| **Event vocabulary curation:** rename or replace `suitable_for` codes with the new site's list (eventlokale's list is in §1), labels in each language, `building.wedding_venue` out | Data (migration rows, catalogue) | S |
| **Capacity per layout** ("120 people seated as a banquet"): today the guest filter is `GuestBand` on `venues.capacity_max` alone. An event searcher picks a layout and a number. Option 1: derived columns `venues.capacity_<layout>_max` (max over rooms), written by `RoomSummary::apply()`, filtered like `capacity_max`, with a partial index each. Option 2: `EXISTS (SELECT 1 FROM rooms r WHERE r.venue_id = v.id AND r.capacity_<layout>_max >= :n)` | **Model change** in Venues (derived columns, `RoomSummary`) + `SearchCriteria` (`layout`, numeric `guests`) + new guests overlay | M |
| **More layouts:** today four (Seminar, Bankett, Apéro, Konzert), exactly eventlokale's four (§1), so parity needs none. Theater, U-Form, Block or Parlament would be our own addition. Each new layout under the current shape is a column pair, a CHECK, an enum case, room-editor keys, content-format keys and test cases. If the list will grow, turn it into `room_capacities (room_id, layout, min, max)` with a CHECK on the layout | Model change (schema) | M (table) / S per added column pair |
| **Guest bands for events:** the four wedding bands (≤50, ≤100, ≤150, >150) do not fit 10–2,000 people. Either other bands (`GuestBand` cases + catalogue) or back to a number input (#11's original) | Filter UI + enum | S |
| **Event landing pages** (`/eventlokale/firmenanlass`, `/…/firmenanlass/zuerich`): `LandingType` is `canton|town|building`, and `LandingPaths::locate()` maps one facet to a path (D10). Adding `event` and an event × canton combination touches `LandingPages` (UNION ALL statement), `LandingPaths`, `LandingPage::keyPrefix()`, CMS landing texts and the sitemap | Search model change (moderate) | M |
| **Catering, technology, equipment:** existing groups, re-curated (e.g. a `catering` group: in-house, external allowed, kitchen for hire, pick of caterers) | Data | S |
| **Price filters** (rent from CHF, per-person price, seminar day package): no column today except the owner's `price_level` | New columns + filter (only if the owner wants them, and venues supply the numbers) | M **(guess)** |
| **Hotel rooms / overnight** (seminar hotels): `amenity.overnight` exists; a bed count would be a column | Data or a column | S |

**Verdict:** it is **mostly data plus new filter UIs**, on a model that already speaks eventlokale's vocabulary. eventlokale itself filters on event type and one guest number only, so parity is S–M: the `event[]` parameter, a number or new bands for guests, re-curated vocabularies. **One real model change** comes with going beyond eventlokale: search by capacity per layout (derived per-venue columns or a room join). A second one follows if the layout list grows (`room_capacities` table). Event landing pages are a moderate change to the landing machinery. Settle the layout list and the per-room vs per-venue semantics before the first migration (Q4), because both are expensive to redo once venues are imported.

### 3.2 Languages from day one

**What S4 delivered and what holds:**
- Localized routes everywhere, with a test that fails on a public route without a path for an enabled locale (`PublicRoutesAreLocalizedTest`). Adding `'fr' => '/fr/…'` per route works as promised.
- One ICU catalogue per module, enums with catalogue labels, `CatalogueParityTest`, `TranslationCompletenessTest`, `TemplatesHaveNoRawTextTest`, `lint:translations`. Catalogue work is additive, as promised.
- `venue_translations`, `room_translations`, `venue_document_translations`, `venue_photo_translations` with `TranslationJoin::localized()` and a per-row fallback to `de`. `chk_*_locale` is a format check, so no migration is needed per language.
- Per-locale tabs in the CMS editors and in the Portal editor and wizard (`EnabledLocale::all()` loops in `VenueTextsType`, `LocaleTitlesType`, `RoomType`, `LandingTextsType`, `TextBlockType`, `LocationEditController`, `WizardController`).
- The content format reads `translations.<locale>.*` (`ContentReader`).
- Content blocks are keyed `(key, locale)`.
- Query parameters are language-neutral codes; no `Accept-Language` negotiation, so caching is safe.

**Where the claim breaks (each verified in the code):**

| # | Gap | Where | Consequence |
|---|---|---|---|
| 1 | **Published requires a `de` row.** "Published means `status = 'published'` plus a default-locale translation" | `ListingSql`, `VenueDetailQuery::PUBLISHED`, `VenueSitemapQuery` (`JOIN … t.locale = :default_locale`), `LandingPages` | A venue in the Romandie with French text only cannot be published. Choose: a per-venue source locale (`venues.source_locale`, the fallback per venue) or a required German row (translation work for every venue) |
| 2 | **Slug resolution is strict per locale, but links use the fallback slug** | `VenueDetailQuery::resolveSlug()` matches `WHERE locale = :locale AND slug = :slug`; cards read the slug through `TranslationJoin` (falls back to `de`) | On `/fr/…` every card of a venue without a French row links to `/fr/<route>/<german-slug>`, which is a 404. Fix: resolve with the fallback too, or give every venue a slug row per enabled locale |
| 3 | **hreflang reuses one slug for every locale** | `HreflangAlternates::forRequest()` passes the request's `_route_params` to every locale | Venue pages (slug per locale), canton pages (`cantons.slug`) and building pages (`landing.building.<code>.slug` per locale) advertise wrong or 404 alternates, also for locales where the venue has no text. It needs per-page alternates supplied by the page (venue: its slugs; landing: the translated facet slugs) |
| 4 | **Sitemaps and `llms.txt` are default-locale only** | `VenueSitemapQuery` (`:default_locale`), `PagesSitemap` | French pages are never in a sitemap. Per-locale URLs with `xhtml:link` alternates are new |
| 5 | **Canton and town slugs are single-language; no exonyms** | `cantons.slug`, `towns.slug`; S4's `town_names` table not built; `AsciiSlugger('de')` in `ImportTownsHandler` and `SlugAllocator` | `/fr/…/zuerich` instead of `/fr/…/zurich`; "Genf" vs "Genève" in the place search; French town pages share German slugs. Needs `canton_names`/`town_names (…, locale, name, slug)` |
| 6 | **`/konto` is German only** | every Portal route is `LocalizedRoutes::UNLOCALIZED`; `portal` catalogue `de`; `portal_users` has no locale; `PortalMailer` mails in German; Portal's `_flash` uses the `admin` domain | Romandie and Ticino managers get a German account. Needs a manager locale (column + switcher) and FR/IT `portal`/`validators` catalogues. The routes can stay unlocalized if the locale comes from the user |
| 7 | **The venue's inquiry mail goes out in the guest's language** | `InquiryMailer` uses `$inquiry->locale()` for both mails | A French guest writes to a Zürich venue in French with a French template. The venue mail needs the venue's language (`venues.contact_locale`, guess) |
| 8 | **CRM mail and templates in one language** | `CrmMailer` → `->locale($this->locales->default())`; `crm_email_templates` has no locale | Staff mailing French venues need per-locale templates (S, staff-side) |
| 9 | **No language switcher** | `templates/layout/*` | New: a header/footer switcher that links to the page's alternates (needs #3) |
| 10 | **Text search folds German** | `geo_fold_de` / `geo_fold` (`ListingSql`, Geo) | Probably fine with `unaccent` for French **(guess, test needed)** |
| 11 | **Hard-coded `de` in tools** | `VenueGeocoder` (`languageCode => 'de'`), `GooglePlaceLookupQuery` (`t.locale = 'de'`), factories, `DemoStory`, `OwnerFolderWriter`/`LegacyFolderWriter` (dropped) | Small fixes |
| 12 | **German-only guards** | `PublicCopyAddressTest` (no "du", no "ß") | Needs FR rules (vous, typography) or a de-only scope |
| 13 | **Translation of content** | 3,500 catalogue lines (about 1,700 public plus portal); venue texts, rooms, documents, photo alts per venue | Not code, but the largest effort. Who translates (venues, staff, machine translation with review) is Q2 |

**Verdict:** S4 made the **catalogue and route** part additive, and the CMS is ready, but the **read paths** (publish, slugs, hreflang, sitemap, geo slugs) and the **mail/portal language** assume German. Plan one `i18n-core` cycle (L) before content, then one catalogue cycle per language. The URL scheme also needs settling: today German is unprefixed and others get `/fr/`. For an event site serving all of Switzerland, a prefix for every language or one domain per language are the alternatives (Q2).

### 3.3 Business model

**What the code assumes today:**
- **Tier** (S6): `Tier` enum `beliebte_location 1, empfehlung 2, top_premium 3, premium 4, basic 5`, a generated `venues.tier_rank` (`CASE tier …` in the migration), `chk_venues_tier`, a badge from the catalogue, ordering `tier_rank` then a daily seeded shuffle (`ListingSql`). The tier changes nothing but rank and badge: every venue gets the same page, photos, documents, inquiry form and contact data.
- **Selling** (S13, #28): a CRM deal has a tier, an amount (`amount_rappen`), a contract start and end, stages `Neu → … → Gewonnen/Verloren`. "Tier auf Location anwenden" dispatches the Venues tier command. `app:crm:renewal-tasks` writes a renewal task 60 days before a contract ends. There is **no automatic downgrade** when a contract ends (an out-of-scope proposal) and no invoicing or payment.
- **Paid entries** (Q20, POR-14): a self-created entry is paid; the first publish writes the CRM task "Rechnung stellen" 30 days later, and the owner invoices by hand. The app enforces nothing: an unpaid entry stays online.
- **Portal** (S16): managers edit texts, address, facts, FAQ, contact, max guests and photos. Name, slug, tier, rooms and documents stay with staff. Managers see their statistics (S17). They do **not** see inquiries.
- **Inquiries** (IQ-1–IQ-4): one inquiry = one venue, free, mailed synchronously to the venue with the guest as `Reply-To`, stored 12 months, ingested into the CRM inbox (status neu/gesehen/erledigt). The venue's reply happens in its own mailbox; nothing tracks it.

**What each option of the new model would change:**

| Option | Changes | Size |
|---|---|---|
| **Other tiers and prices** | `Tier` cases, the generated `tier_rank` CASE, `chk_venues_tier`, catalogue labels, CRM deal tier options, `VocabularyChecksTest`, demo data, badges | S (one migration and enum) |
| **Tier entitlements** (eventlokale's model: photo, PDF and event-type limits per tier, panorama header, videos; ours could add no inquiry form or no website link for a basic entry) | New: one `TierBenefits` policy in Venues read by the detail page (hide form/contact/website), Portal (photo limit), Inquiries (refuse for a basic venue), Cms (warnings) | M |
| **Paid entries that expire** (listing visible only while paid) | New: `venues.paid_until` or derive from the won deal's contract period; a nightly step that unpublishes or downgrades, plus mails; the out-of-scope "automatic downgrade" becomes required; CRM `CompanyStageSync` already derives customer/former customer | M |
| **Online payment / self-serve upgrade** (card, TWINT, QR-bill invoice) | New: a payment provider, webhooks, invoices, VAT (S13 says CHF only, no VAT), Portal checkout, the Datenschutz processor. The most expensive item on this list | L+ |
| **Multi-venue request ("Sammelanfrage")** from the Merkliste or the listing | New in Inquiries: one form, N `inquiries` rows sharing a request id, N venue mails, one guest mail; the limiter counts the request; the CRM ingests N | M |
| **Event-shaped request** (company, event type, layout, start and end time, flexible date, catering, budget, invoice address) | Adapt: `Occasion` → event types (reuse `suitable_for` codes), new `inquiries` columns, form, mails, CRM inbox columns | M |
| **Quote request answered in `/konto`** | New: inquiries per venue in the Portal (an out-of-scope proposal today), a reply or quote form, status per inquiry (open, offered, won, lost), a mail to the guest, perhaps a guest link to view the quote. Turns the mail relay into a light workflow | L |
| **Booking with availability** | New: room calendars, availability data from venues, holds and confirmations. S15 already noted that no availability data exists anywhere. Out of proportion for a listing site **(opinion)** | XL |
| **Boosts** (eventlokale: top 10 of a canton for a month, nationwide, "Location des Monats" on the home page) | New: a time-boxed rank override (`venue_boosts (venue_id, scope, canton_code, starts_on, ends_on)`) read by `ListingSql`'s order and the home Spotlight, sold as a CRM deal; mind the cache (a listing is cached until midnight, so boosts switch at midnight) | M |
| **Event Creator / packages** (eventlokale beta: priced day packages, booking states, billing) | New: venue package prices per slot, meal and level, a configurator, quotes and bookings. A product of its own | XL |
| **Pay per lead** | Inquiry counts per venue exist (Stats, `inquiries`); a monthly report per venue for invoicing is S, automatic billing is part of the payment item | S / L |

---

## 4. How to fork

### Option A: clone with full history, strip and rename in place (recommended)

`git clone --no-local` into a new directory, push to a new private GitHub repository, tag `fork-point`, and add hochzeitslocation as a second remote (`git remote add hzl …`). Cycle F0 then renames, strips and squashes.

- **For:** the fastest start, everything green on day one. Shared history makes porting fixes cheap: `git cherry-pick` (or `format-patch`/`am`) from `hzl/main` applies with a three-way merge as long as the files have not diverged, which matches "ported by hand when needed". `git blame` explains old decisions.
- **Against:** 45 MB of `.git` history, wedding commit messages, and owner/operations facts in history (`djweddin`, domains). No secrets: `.env.local` was never committed (`.gitignore`, and `git log --all -- '*.env.local'` is empty). The eventlokale screenshots in `docs/reference/` stay in history; they are documentation in both projects anyway.
- **Migrations:** squash the 25 migrations into one hand-written baseline (`VersionYYYYMMDDHHMMSS` at fork time) from `pg_dump --schema-only` of a freshly migrated database, reviewed by hand, minus the dropped tables (rating/review, wedding columns), plus the seeded rows (cantons, taxonomy). The new project has no production data, so the chain's data steps (#46 Liechtenstein rows, #55/#56 CRM reshapes, #53 drop) have no reason to stay. `SchemaInSyncTest` and `VenueSchemaMigrationTest` prove the baseline. This conflicts with no cherry-pick, because new migrations are new files on either side.

### Option B: a fresh repository from a cleaned snapshot, no history

Copy the working tree (without `.git`, `var/`, `vendor/`, `content/`, `.rpi-tracking/`), apply the same strip and rename, then make one initial commit.

- **For:** a clean, small repository with no wedding or operations history.
- **Against:** porting a fix becomes `git diff | git apply -3` without a merge base, which fails more often and needs manual merges. The strip and rename work is the same as A.

### Option C: a fresh skeleton, modules copied in one by one

A new Symfony skeleton, then Shared, Geo, Media, Admin, … copied in cycle by cycle, each reviewed.

- **For:** each module is consciously re-read, and nothing unwanted rides along.
- **Against:** the modules interlock (Admin's extension points, Shared's content blocks and cache, phpat module table, test support traits, Makefile and CI), so the skeleton re-creates the same tree more slowly. Weeks of cycles before the first page renders **(estimate)**, and no merge base for fixes.

### Recommendation: A

It gives the shortest path to a green fork and keeps cherry-picks cheap. Hygiene in F0:

- **Renames:** brand only (`site.name`, `site.contact_email`, domain, 2FA issuer, `CheckProductionEnvCommand`, deploy/ops strings, Compose and Makefile names, logo, favicon). Route paths and wedding copy belong to the feature cycles that rewrite them, not to a global search-and-replace.
- **Strip:** the Wix/owner and legacy importers, the eventlokale crawler, the customer-sheet import, the retired rating entities, `docs/reference/` boards (the new brand gets its own), S14's wedding groups (or keep the useful parts, see the Venues row).
- **Content and demo data:** no venue data comes along (fact 1). DemoStory stays fictional.
- **`.claude` and docs:** keep the convention rules, condense the module notes to current state, write a new `docs/rpi-cycles.md` and `docs/product.md`, and restate the owner rules in the new project's memory.
- **Secrets and environment:** new `APP_SECRET`, `ADMIN_TOTP_KEY`, Neon URL, mailbox password, Google keys, Sentry DSN; a new `deploy/env.local.example` checked by `app:prod:check-env`.

**Infrastructure the owner sets up again** (order from `docs/operations.md` §First-time setup):
1. Domain(s), plus a decision on one domain with `/fr/` or one per language (Q2).
2. A Hostpoint website (a new account user or the same account with a second site), SSH, PHP 8.5, docroot.
3. A Neon project in Frankfurt on Postgres 18, plan choice.
4. A mailbox, SPF, DKIM, DMARC on the new domain.
5. Sentry: an EU organisation or a new project, a cron monitor, an uptime monitor.
6. Google Cloud keys limited to the new domain: Maps JavaScript and Map ID (near-me), Maps Embed (detail map), Places (rating), Geocoding (import).
7. A GitHub repository (account `cvtmal`), the environment `production` with four secrets, a deploy key and `deploy-gate`, backup secrets with the backup workflow off until launch.
8. The crontab, `.env.local`, the first admin with TOTP.
9. Legal: Impressum operator (the sister site's operator again? D27: placeholders), Datenschutz processors.

---

## 5. Effort: cycle list for the new project

Sizes: S ≈ half a day, M ≈ one to two days, L ≈ three days or more of cycle work **(estimate, in this repo's pace)**. The order is a dependency order; cycles at the same step can run in parallel lanes.

| # | Slug | Size | Depends on | What |
|---|---|---|---|---|
| F0 | `fork-baseline` | M | — | Clone (option A), rename the brand and ops strings, strip the importers, crawler, customer import and retired rating; squash migrations; condense `.claude` notes; CI green on the new repository |
| F1 | `owner-decisions` (no code) | S | — | Record the answers to §6 as settled decisions S-E1… (languages and URL scheme, layouts, tiers and entitlements, request type, content source, brand) |
| F2 | `i18n-core` | L | F0, F1 | Gaps 1–9 of §3.2: per-venue source locale or required `de`, slug fallback, per-page hreflang, per-locale sitemaps, `canton_names`/`town_names`, language switcher, venue mail language, manager locale |
| F3 | `event-vocabulary` | M | F0, F1 | Event types (`suitable_for` re-curated), catering, technology, equipment, remove wedding groups; migration rows, catalogues (de), content format, DemoStory |
| F4 | `room-layouts` | M | F3 | Layout list (likely `room_capacities` table), per-venue derived capacities, CMS room editor, detail room dialog, content format |
| F5 | `filter-event` | M | F3, F4 | `event[]`, `layout` + guest number in `SearchCriteria`, home hero search, guests overlay, more overlay sections, `FilterDataQuery`, performance check at ×10 |
| F6 | `brand-design` | L | F1 | New boards, tokens, logo, favicon, mail look, contrast table; home page board (here it was #34–#41, eight cycles; a fork re-skins instead of rebuilding, hence L, not 8×) |
| F7 | `event-copy-de` | M | F3, F5 | German public copy: routes, titles, meta, landing texts, home, legal, mails, Merkliste copy |
| F8 | `landing-event` | M | F2, F5 | Event and event × canton landing pages, CMS landing texts, sitemap, JSON-LD |
| F9 | `inquiry-event` | M | F3, F2 | Event fields on the request, mails per venue language, CRM inbox columns |
| F10 | `catalogues-fr` | M | F7 | French catalogues (public, portal, validators), French paths, legal in French; review by a native speaker |
| F11 | `portal-event` | M | F4, F2 | Rooms and documents in `/konto` (the out-of-scope recipe), wizard step for event facts, `/konto` in FR |
| F12 | `business-model` | M–L | F1 | Tiers, entitlements, paid-until and expiry (per Q3); CRM deal tiers |
| F13 | `multi-inquiry` | M | F9 | Sammelanfrage from the Merkliste (if chosen) |
| F14 | `content-onboarding` | M | F2–F4 | Whatever the content source is (Q1): import mapping into the content format, or a mailing to venues with self-registration in `/konto` |
| F15 | `prod-setup` | M | F0, owner infra | Deploy, cron, backups, monitors on the new host; `docs/operations.md` rewritten |
| F16 | `launch-verification` | M | all | Lighthouse, budgets, keyboard, 390/1440 px, both languages |
| later | `catalogues-it`, `catalogues-en` | S–M each | F10 | Additive once F2 is done |
| later | `quote-flow` / `payments` | L / L+ | F12 | Only if the owner picks them |

**First:** F0, then F1 (decisions), then F2 and F3 in parallel. F2 must land before any content import or French catalogue, because it changes the published predicate and the slug model. F6 can run beside F2–F5 in its own lane. Total: about 16 cycles to launch in one language pair (DE + FR), against 56 for hochzeitslocation **(estimate)**.

---

## 6. Risks and open questions for the owner

1. **Where do the venues come from?** hochzeitslocation launched on the owner's own Wix export (S14). The new site has no source. eventlokale's data is not ours, and its crawl permission (#49) was for the wedding list as a private list. Copying hochzeitslocation's venues contradicts "fully separate", unless the venues agree. *Why it matters:* without content the site launches empty, and the import shape (F14) depends on the source.
2. **Which languages, and which URL scheme?** DE + FR at launch, IT/EN later? One domain with `/fr/` (German unprefixed, as built) or one domain per language? And who translates venue texts: the venues, staff, or machine translation with review? *Why:* it decides gap 1 of §3.2 (source locale per venue or a required German row) and the size of F10.
3. **Which business model?** Tier names and prices; whether tiers unlock features (inquiry form, photos, contact data); whether a paid entry expires and goes offline; pay per lead; online payment or invoice. *Why:* entitlements and expiry touch Venues, Inquiries, Portal and Crm together (F12), and online payment is the most expensive item in this report.
4. **Inquiry or quote?** A single-venue inquiry (as today), a multi-venue request from the Merkliste, or a quote workflow in `/konto` with statuses? *Why:* M versus L, and it changes what managers must do on the site.
5. **Room layouts and capacity semantics.** Keep eventlokale's four layouts (Bankett, Seminar, Apéro, Konzert) or add Theater, U-Form, Block or Parlament? Is a layout a search filter at all (eventlokale has none)? Does the search match one room's capacity or the venue's best room? *Why:* the schema shape (column pairs or a `room_capacities` table) is cheap to choose now and expensive to change after the import.
6. **Brand and the overlap with hochzeitslocation.** Name, domain, look; and does the event site list weddings too? *Why:* the brand design took eight cycles here (#34–#41). Listing the same venues on both owner sites invites duplicate-content and cannibalisation questions on Google, and "fully separate" CRMs mean the same venue operator is sold twice by two back offices.
