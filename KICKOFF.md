Superseded by `docs/rpi-cycles.md` and `CLAUDE.md`; kept for history.

# deine-eventlocation.ch: kickoff

**Read this first, before `CLAUDE.md`.** This folder is a fork of `hochzeitslocation-symfony`, a Swiss wedding-venue site. It was taken on 2026-11-06 at commit `7e96eee`, tagged `fork-point`. Everything else in the repo (`CLAUDE.md`, `docs/`, `.claude/rules/`, the code) still describes the wedding site. Trust those files for **how** things are built: architecture, conventions, tooling, deploy. Do not trust them for **what** this product is. This file wins wherever they disagree, until cycle F0 has rewritten them.

## 1. The product

**deine-eventlocation.ch** is a Swiss listing of event venues: company events, seminars, birthdays, apéros, Christmas parties, general meetings and so on. It has the same shape as hochzeitslocation.ch:
- a home page;
- a listing with filters;
- one detail page per venue;
- a Merkliste without login;
- an inquiry form;
- a back office (CMS, CRM, statistics);
- a portal for venue managers (`/konto`).

It is modelled on **eventlokale.ch**'s features and behaviour. Its texts, photos, venue data and branding are not ours. Never copy them.

## 2. Settled decisions (owner, 2026-11-06)

| # | Decision |
|---|---|
| E1 | **Fully separate** from hochzeitslocation.ch: own database, own CMS and CRM, own venues and managers. Nothing is shared at runtime. |
| E2 | **Fork once, then diverge.** Fixes from hochzeitslocation are ported by hand when needed, with `git cherry-pick` from the remote `hzl` (fetch only; pushing to it is blocked). There are no shared packages and no multi-site codebase. |
| E3 | **German only.** The i18n machinery stays (localized routes, `*_translations` tables), but no French, Italian or English is planned. The research found that adding a language later needs an `i18n-core` cycle first, because the wedding site's promise that a language only adds catalogues and route paths does not hold. |
| E4 | **"du" everywhere** in the public copy, mails and `/konto`. The wedding site uses "Sie", and `tests/Unit/Shared/PublicCopyAddressTest.php` enforces it. Turn that test around so it enforces "du", and rewrite every public catalogue. The staff catalogues (`admin`, `cms`, `crm`) are informal already. Swiss German still means "ss", never "ß". |
| E5 | **Event filters** replace the wedding ones: event types (Firmenanlass, Seminar, Geburtstag, Apéro …), catering, technology. Seating layouts are already on rooms (Bankett, Seminar, Apéro, Konzert). |
| E6 | **Another business model** than the wedding site: other tiers and pricing for venues, maybe expiring paid entries. Details are open, see §3. |
| E7 | **Domain:** `deine-eventlocation.ch`. Also register `deineeventlocation.ch` as a redirect. |

## 3. Open questions for the owner: answer them before cycle F1 closes

1. **Where do the venues come from?** This is the biggest risk. hochzeitslocation launched on the owner's own Wix export, the new site has no source, eventlokale's data is not ours, and E1 rules out copying hochzeitslocation's venues. Without a source the site launches empty.
2. **Business model:** tier names and prices; whether tiers unlock features (photos, PDFs, event types, inquiry form, contact data); whether a paid entry expires and goes offline; invoice or online payment. For reference, eventlokale charges Premium CHF 449, Top Premium 949 and Top Premium+ 1,998 a year, plus boosts and newsletter ads.
3. **Inquiry:** one venue per request as today, a request to several venues from the Merkliste (eventlokale allows up to 10), or a quote workflow in `/konto`.
4. **Room layout as a search filter** ("120 people for a seminar")? eventlokale has no such filter. Without it the work stays small.
5. **Brand:** name spelling, colours, logo, typeface, the look of the boards. The wedding brand (pink, cream, Figtree, `logo-hzl.png`) must go.
6. **Legal and operator:** the Impressum operator (the same company as hochzeitslocation?), the processors for the Datenschutz page.
7. **Does the site list weddings too?** If it does, it overlaps with hochzeitslocation.ch, with duplicate content on Google and the same venue sold twice by two CRMs.

### 3a. Owner answers: cycle F1 `owner-decisions` (owner, 2026-11-06, via the tech lead)

These are settled. F0 copies them verbatim into `docs/rpi-cycles.md` beside E1–E7; from F0's merge on, the product owner keeps them there.

| # | Decision | Feeds |
|---|---|---|
| D1 | **Venues come from three sources.** (a) Managers register themselves in `/konto`, after a mailing and calls by the owner. (b) The owner's own contacts and spreadsheet, imported through `app:venues:import`. (c) hochzeitslocation customers who also host events, **only with their consent**, imported once as new records with new texts. This is a one-time import, not runtime sharing, so E1 holds. | F14 |
| D2 | **Freemium.** A free basic entry plus 2–3 paid tiers, invoiced by hand; no online payment. When a paid entry expires it falls back to free and never goes offline. Paid tiers unlock more photos, PDFs, a better ranking and visible contact data. Every venue, free ones included, has the inquiry form. | F12 |
| D3 | **One venue per inquiry** at launch. A request to several venues from the Merkliste (F13) comes after launch. | F9, F13 |
| D4 | **No room-layout search filter.** Visitors search by guest number only; the layouts show on the detail page. F4 stays small, and F5 has no layout criterion. | F4, F5 |
| D5 | **The owner supplies the brand:** logo, colours, typeface. F6 implements the brand; it does not design one. | F6 |
| D6 | **The operator is a different company** from hochzeitslocation's. | F7 |
| D7 | **Weddings are listed:** "Hochzeit" is one event type among the others. The tech lead had advised against it; the owner overruled. A venue's texts must differ between the two sites. | F3, F8, F14 |
| D8 | **Git:** teammates may push branches, open PRs and squash-merge to `main` without asking, and resolve conflicts together with the tech lead. No direct pushes to `main`. Deploys stay the owner's. | all |
| D9 | **Name and contact:** the site is called "deine-eventlocation.ch", and its contact address is `hallo@deine-eventlocation.ch`. | F0, F7 |
| D10 | **Hosting is not Hostpoint;** the host is not chosen yet. It must offer PHP 8.5, Postgres 18 (or Neon reached from outside), cron and SSH. F0 leaves the deploy scripts as they are, and F15 adapts them once the owner picks a host. | F0, F15 |
| D11 | **The eventlokale crawler stays** (overrides the "Drop" row in §4). The owner wants eventlokale's venues as a content source; D12 settles how. | F0, F14 |
| D12 | **Source (d), eventlokale venues.** The crawler collects eventlokale's public facts as internal working data before launch; nothing of it is published. eventlokale's texts and photos are **never published**; the owner writes new texts. Public photos come from the Google Places API, loaded live: never stored or cached, with the uploader's name on each photo, on the detail page only and never on cards. They show until the venue uploads its own photos in `/konto`. | F14 |

**Owner inputs still needed, and the cycle each one blocks:**

| Input | Needed before |
|---|---|
| The host (D10) | F15 |
| Logo, colours, typeface | F6 |
| Operator name and address, the processors for the Datenschutz page | F7 |
| Tier names and prices (2 or 3 paid tiers), and the photo and PDF limits per tier | F12 |
| The spreadsheet for D1(b), and the consent wording for D1(c) | F14 |
| D12 follow-up 1: what a **listing card** shows for a venue without its own photos, which at launch is most of them. PO recommendation: a neutral brand placeholder per event type, and the owner's own photos where they have the rights | F6, F14 |
| D12 follow-up 2: whether the crawled **facts** (address, capacity, rooms, event types) are published as they are, or only once the owner or the venue has checked them. PO recommendation: publish an entry only after the owner has written its text and checked its facts; until then it stays a draft | F14 |

## 4. What carries over (German only)

Full analysis: `docs/research/fork-research.md`, copied from the wedding repo. Read it, but it was written before E3, so ignore its French and language parts (§3.2, `i18n-core`, `catalogues-fr`).

| Class | Share | What |
|---|---|---|
| As is | ~50 % | Shared building blocks, Geo, Media, Admin (staff login, TOTP, audit, UI kit, Vue islands), Stats, Favorites (Merkliste), the frontend system, deploy, cron, backup, CI, Docker, Makefile, the test harness, the architecture and convention rules |
| Adapt | ~40 % | Venues, Search, Seo, Inquiries, Cms, Portal, Crm: wedding wording, routes, filters, the "Hochzeit" fields, tiers |
| New | ~5–10 % | Event-type search (and layout search if Q4 says so), business-model pieces, the new brand |
| Drop | small | The Wix, legacy and owner importers (`Venues\…Wix*`, `Legacy*`, `Owner*`), ~~the eventlokale crawler~~ (kept, D11), the customer-sheet import (`Crm\…Customer*`), the retired rating entities (`VenueRatingHighlight`, `VenueReview`), the wedding facts (ceremony, vendor, usage, party/curfew) |

Where "wedding" is baked in (the rename checklist, details in the research §2):
- route paths `/hochzeitslocation…`;
- the enums `Occasion`, `TermGroup::{Ceremony,Vendor,Usage,…}`, `GuestBand`, `HomeCategory`, `Tier`, `LocationCard::Wedding`, `WizardStep::Wedding`;
- the taxonomy rows in migrations;
- about 150 catalogue lines (`search`, `seo`, `inquiries`, `venues`, `portal`);
- `site.name` and `site.*` in `config/services.yaml`;
- `trusted_hosts`, the 2FA issuer, `CheckProductionEnvCommand`;
- `bin/deploy`, `.github/workflows/deploy.yaml`, `public/.htaccess`, `deploy/crontab.txt`;
- `DemoStory`, `CrmStory`, `PortalStory`.

## 5. Cycle plan

The sizes are rough: S about half a day, M one to two days, L three days or more. Numbers follow the research, so F2 and F10 (languages) are gone.

| # | Slug | Size | Depends on | What |
|---|---|---|---|---|
| F0 | `fork-baseline` | M | — | New GitHub repo, then rename the brand and ops strings and strip the importers, customer import and retired rating entities (the eventlokale crawler stays, D11). Squash the 25 migrations into one hand-written baseline: the new site has no production data. Condense `.claude/rules/module-*.md` to "current state" notes. Write a new `CLAUDE.md`, `docs/rpi-cycles.md` (E1–E7 as settled decisions, keeping the old S1–S17 that still hold) and `docs/product.md`. Turn the du/Sie test around (E4). CI green. |
| F1 | `owner-decisions` | – | — | No code: record the answers to §3 as decisions. |
| F3 | `event-vocabulary` | M | F0, F1 | Event types (re-curate `suitable_for`), catering, technology; the wedding groups out; migration rows, catalogues, content format, DemoStory. |
| F4 | `room-layouts` | S–M | F3 | Layout list and capacities per room, the CMS room editor, the detail room dialog. |
| F5 | `filter-event` | M | F3, F4 | Event type and guest number in `SearchCriteria`, the home search, the filter overlays, `FilterDataQuery`. |
| F6 | `brand-design` | L | F1 | New boards, tokens, logo, favicon, mail look, contrast table. Runs in its own lane beside F3–F5. |
| F7 | `event-copy-de` | M | F3, F5 | All German public copy in "du": routes, titles, meta, landing texts, home, legal pages, mails, Merkliste. |
| F8 | `landing-event` | M | F5 | Landing pages per event type and per event type × canton, their CMS texts, sitemap, JSON-LD. |
| F9 | `inquiry-event` | S–M | F3 | Event fields on the inquiry (company, layout, date and time, catering, budget), mails, CRM inbox columns. |
| F11 | `portal-event` | S–M | F4 | Rooms and documents in `/konto`, and a wizard step for the event facts. |
| F12 | `business-model` | M–L | F1 | Tiers, entitlements and expiry as Q2 decides, CRM deal tiers. |
| F13 | `multi-inquiry` | M | F9 | Request to several venues from the Merkliste, only if Q3 wants it. |
| F14 | `content-onboarding` | M | F3, F4 | Whatever Q1 decides: an import mapping into the content format, or self-registration in `/konto` plus a mailing. |
| F15 | `prod-setup` | M | F0, owner infra | Deploy, cron, backups and monitors on the new host; rewrite `docs/operations.md`. |
| F16 | `launch-verification` | S–M | all | Lighthouse, budgets, keyboard, 390/1440 px. |

**Order:** F0, then F1, then F3 and F6 in parallel lanes, then the rest by dependency. That is about 13 code cycles to launch, 12 without F13. Quote workflows and online payment are out unless the owner picks them; each would be large.

## 6. Infrastructure the owner sets up again

The order follows the wedding repo's `docs/operations.md` §First-time setup:
1. Domains `deine-eventlocation.ch` and `deineeventlocation.ch`.
2. A web host with SSH, PHP 8.5 and cron, and its docroot. Not Hostpoint; still to be chosen (D10).
3. A Neon project in Frankfurt on Postgres 18.
4. A mailbox with SPF, DKIM and DMARC on the new domain.
5. Sentry: a project, a cron monitor and an uptime monitor.
6. Google Cloud keys limited to the new domain: Maps JavaScript and a Map ID, Maps Embed, Places.
7. A GitHub repository on the account `cvtmal`, with CI, the `production` environment and its secrets, a deploy key and `deploy-gate`.
8. The crontab, `.env.local` and the first admin with TOTP.

New secrets are needed throughout: `APP_SECRET`, `ADMIN_TOTP_KEY`, the Neon URL, the mail password, the Google keys, the Sentry DSN. Nothing is reused from hochzeitslocation.

## 7. Local setup

- This folder is its own Compose project (`deine-eventlocation`). The wedding repo's main lane already uses 8080, 5432 and 8025. Before `make init`, add a git-ignored `compose.override.yaml` with nginx `8090`, database `5442`, mailpit `8035` (`!override` ports) and a network subnet `10.247.90.0/24`. The local Docker subnet pool is exhausted, so a new project needs a pinned subnet. Never `docker network prune`.
- Never run `composer` or `bin/console` on the host; use the `make` targets (see `CLAUDE.md`).
- `git remote -v`: only `hzl` (fetch only). Add `origin` once the owner has created the GitHub repo, as F0's first step.
- Git-ignored state did not come along: `.rpi-tracking/`, `content/`, `.env.local` and the auto-memory. The new project starts with an empty memory.

## 8. How we work (carried over from hochzeitslocation; save these as memories in the first session)

- **Roles:**
  - The owner decides scope and what goes live.
  - A product-owner session owns the WHAT (`docs/product.md`, the decisions and requirements in `docs/rpi-cycles.md`).
  - A tech-lead session owns the WHY and HOW and spawns developer teammates. A teammate that is idle above 180k tokens of context is shut down, and new work goes to a fresh one.
- **RPI cycles:** every cycle runs Research → Plan → Implement → Review with the `rpi:*` skills. One worker owns a cycle, with one fresh sub-agent per phase. The review comes from a fresh agent that did not implement the work. Artifacts go to `.rpi-tracking/`. After two failed rework rounds, ask the owner.
- **Git:** the owner allowed commit and push for hochzeitslocation; ask the owner whether the same holds here. **Deploys are the owner's alone.**
  - One commit per cycle, with the cycle slug as the message.
  - Before pushing: `git fetch && git rebase origin/main`, then run `make lint && make test` again.
  - Rework goes in a new `<slug>-finish` commit, never `--amend`.
  - A `Co-Authored-By` trailer is fine, but no session URLs or model names in commits or PRs.
- **Order of work:** `make cs-fix` → `make lint` → `make test`, plus the UI checks for UI cycles.
- **Keep it simple:** at most two back-office users, never on the same thing, so no deep concurrency work. No privacy machinery beyond what the law needs. When only unrealistic input fails, lower the limit and refuse it rather than engineering for it.
- **Research briefs:** ask the owner 2–3 key facts first, then deliver 2–4 options and a recommendation.
- **Teammates** run the final gate and the push in the foreground. The lead checks pushes with `git`, not from status messages.
- **Runbooks** for production steps: a dry run first, both outcomes written down, and no claims about production's state from local evidence.
- **Pushing:** if a push says "Repository not found", the `gh` account is wrong. Ask the owner to `gh auth switch` to `cvtmal`, and never inject a token.

## 9. First steps for the first session

1. Read this file, then `CLAUDE.md`, `docs/research/fork-research.md` §2 and §4, and `.claude/rules/code-quality.md`.
2. Save §2 and §8 as memories.
3. Ask the owner the open questions in §3, and whether commit and push are allowed without asking.
4. Plan F0 as an RPI cycle once the owner has created the GitHub repository.
