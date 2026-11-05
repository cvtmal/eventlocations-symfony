# Hochzeitslocation: product

_Last updated: 2026-11-05 (through #53; CRM Locations: #54, #55 live, #56 in progress) · Owner of this file: the product owner (the WHAT). The tech lead owns the HOW. Scope, settled decisions, requirements and the cycle log stay in `docs/rpi-cycles.md`; operations in `docs/operations.md`._

## What it is

A Swiss wedding-venue listing site at **hochzeitslocation.ch**, run by Pick n Roll GmbH. Couples search venues, save favourites and send inquiries. Venues are sold paid tiers. Venue managers can maintain their own entry. It is live since 2026-11-02 with 249 venues (245 Swiss, 4 in Liechtenstein). Language: German only for now.

It is not an eventlokale clone: no magazine, no provider listings, no event types, no award.

## Who it serves

| Audience | What they need | Where |
|---|---|---|
| Couples | Find a fitting venue, compare, save, ask | Public site |
| Venue managers | Take over or create their entry, keep it current, see their figures | `/konto` |
| Our staff (max 2 users) | Edit venues, sell tiers, follow up with venues and inquiries | `/admin` |
| The owner | Revenue from tiers and paid entries, low maintenance | all of the above |

## What the product does today

**Public site**
- Home page, one listing page with filters (region incl. place/postcode and radius, guests, wedding facts, more), canton landing pages, one detail page per venue with photos, facts and map, similar venues.
- Guest filter with four size bands (bis 50, bis 100, bis 150, über 150 Gäste), matched against a venue's maximum capacity; a select on the listing overlay and the home search, "Alle Gästezahlen" for none. Old `?guests=N` links fall into their band (#51, `52313d0`).
- "In der Nähe" page at `/hochzeitslocation-in-meiner-naehe`, linked from the header, phone menu and footer: the visitor shares a location or types a PLZ/Ort and sees venues within 30 km, nearest first, on a Google Maps map; without a location all pins show. Until the owner sets `GOOGLE_MAPS_JS_KEY` and the map ID on the server, the page shows the controls and the list without a map (#52, `13c3b49`).
- Detail page shows Google's stars and review count (#53) once a venue has a place ID; our own ratings and reviews and the Bewertungen tab are gone. Staff set the place ID in the CMS card "Google-Bewertung".
- Merkliste (favourites) without login, kept in a cookie.
- Inquiry form on every venue; the inquiry goes to the venue by e-mail and is stored for the CRM.
- Ranking by paid tier (Beliebte Location, Empfehlung, Top Premium, Premium, none), shuffled daily within a tier.
- SEO basics (meta, structured data, sitemap), legal pages, own cookieless statistics.
- Googlebot is currently blocked in `robots.txt`.

**Back office (`/admin`, staff only, 2FA)**
- CMS: venues, photos, rooms, documents, landing pages, text blocks.
- CRM: companies, contacts, deals pipeline, tasks, timeline, e-mail sending, inquiries, customer import, statistics.

**Location accounts (`/konto`)**
- A manager registers (name and e-mail), asks access to an existing venue or creates a new one in a short wizard, and edits it. The first publication and every return from offline are accepted by the owner. New entries are paid; the owner invoices outside the app, and the app writes the CRM task "Rechnung stellen" 30 days after the first publish.

## Open product questions and decisions waiting

| # | Item | Needs | Status |
|---|---|---|---|
| 1 | Security audit triage (2 high, 8 medium, 17 low) | Owner, when they choose to pick it up | Set aside by the owner on 2026-11-05. PO proposal when it returns: H1 guest-copy relay and H2 IPv6 limiter keys first, with M6, M8, M1 |
| 2 | CRM "Locations" merge | Owner go given 2026-11-05 (option A, all 11 defaults); deploys the owner's | #54 and #55 live; #56 crm-locations-one-per-venue in progress. See "CRM Locations" below |
| 3 | Q3 pin check: 13 doubtful map pins and 1 venue without a pin | Owner, by hand | In `content/owner/q3-pin-check.md` |
| 4 | When to let Googlebot in | Owner | Blocked on purpose after launch; no date set |
| 5 | Price of a self-created entry | Owner | Not set; plays no part in the app |
| 6 | Server settings for the "In der Nähe" map: `GOOGLE_MAPS_JS_KEY` and the map ID | Owner | Page works without them, but shows no map |
| 7 | Place IDs for Google ratings | Staff, per venue, in the CMS card "Google-Bewertung" | Billing country answered (Switzerland). Stars show on a detail page only once the venue has a place ID |

## CRM "Locations" (decided 2026-11-05)

Research: `.rpi-tracking/research/2026-11-04/crm-locations-merge-options.md`. The owner chose option A (evolve in place) and accepted every default.

- The CRM's Firmen and Kontakte become one record, **Location**. A Location may have one Eintrag (venue); without one it is a prospect. Prospects sit in the same list under an "Ohne Eintrag" tab. Staff link a new venue to a prospect by hand; there is no automatic name match.
- The CMS venue screens are renamed **Einträge** (staff screens only). The public site and `/konto` keep "Location".
- People become a contact card on the Location, with no page, list or timeline of their own. Removing one deletes the row only. Contacts without a company are erased after a dry run shows the count.
- Couples leave the CRM and exist only as Anfragen, under the existing 12-month purge. No composer on an Anfrage. Couples already in the CRM are erased at the switch (Anfragen stay, notes blanked, tasks removed).
- A deal shows its Location's contact card, and its Eintrag comes from the Location. A manager confirmed in `/konto` is copied once onto the card.
- Cycles, in order: #54 `crm-locations-names` (live: the CRM says "Locations" at `/admin/crm/locations…` with 301s from `/firmen`, the CMS says "Einträge", merge token `{location.name}` with `{company.name}` still working, CSV `locations-YYYY-MM-DD.csv`), then #55 `crm-locations-contacts` (live: each Location has a contact card with add, edit and remove dialogs; the Kontakte menu is gone and `/admin/crm/kontakte/{id}` forwards to the Location; couples live only as Anfragen, a reply is a mailto, and the couple purge cron step is gone), then #56 `crm-locations-one-per-venue` (in implementation, goes out alone). The owner gave the go for all three on 2026-11-05.

## Parked

- **#50 bridebook-crawler:** Bridebook's Cloudflare challenges our requests. Waits for an export or an allowlist from Bridebook.
- **Newsletter tool:** the owner's team is discussing it. No build planned.

## Roadmap (proposed order, PO)

1. Security batch (H1, H2, M6, M8, M1) once the owner triages.
2. CRM Locations merge in three cycles once the owner picks an option: names, contacts, one location per venue.
3. Remaining security mediums (M2–M5), later M7.
4. Content quality: facts for the venues without owner data, and the missing pins.
5. Later, only on demand: more languages (FR, IT, EN), map view, bulk inquiry from the Merkliste, manager extensions (rooms, documents, inquiries). Full list: "Out of scope / proposals" in `docs/rpi-cycles.md`.

## Principles

- Keep it simple. Two staff users at most, no privacy machinery beyond what the law needs, no extreme-input engineering.
- The owner decides scope and what goes live. Deploys are the owner's alone.
- One write path: every change to a venue goes through the same commands, whoever makes it.
