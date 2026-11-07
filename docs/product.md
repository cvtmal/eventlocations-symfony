# deine-eventlocation.ch: product

_Last updated: 2026-11-07 · Owner of this file: the product owner (the WHAT). The tech lead owns the HOW. Scope, settled decisions, requirements and the cycle log live in `docs/rpi-cycles.md`; the fork brief is `KICKOFF.md`; the two business requirement documents are `docs/prd/CMO_PRD.md` (go to market) and `docs/prd/CTO_PRD.md` (product blueprint)._

## What it is

A Swiss listing of **event venues** at **deine-eventlocation.ch**: company events, seminars, birthdays, apéros, Christmas parties, general meetings and weddings. Organizers search venues, save favourites and send an inquiry. Venues get a free entry and can buy a paid tier. Venue managers maintain their own entry. German only, "du" everywhere. It is a separate site from hochzeitslocation.ch, with its own database, back office and venues (E1). The operator is a different company (D6). Status: pre-launch, no production data.

It is modelled on eventlokale.ch's features and behaviour. Its texts, photos, venue data and branding are not ours and are never published (D12).

## Who it serves

| Audience | What they need | Where |
|---|---|---|
| Organizers (corporate, private, planners) | Find a venue that fits event, place and guest number; compare rooms and photos; one clear inquiry | Public site |
| Venue managers | A prepared entry to check and claim, no new system, inquiries by e-mail, honest figures | Preview link, `/konto` |
| Our staff (max 2 users) | Edit entries, sell tiers, follow up with venues, handle complaints | `/admin` |
| The owner | A small recurring revenue: 61 paying venues at CHF 199 a year is about CHF 1,000 a month before costs | all of the above |

## What the product does today

The code is the wedding site's, renamed and stripped (cycle `fork-baseline`, 2026-11-06). The look is our own since F6 (indigo and vermilion, Hanken Grotesk, `docs/brand.md`); wording, filters and vocabulary are still the wedding site's until F3, F5, F7b and F8.

- **Public:** home page, one listing with filters, one detail page per venue with photos, rooms, documents and map, canton landing pages, the Merkliste without login, an inquiry form on every venue, SEO basics, own cookieless statistics.
- **Back office (`/admin`, staff only, 2FA):** CMS for entries, photos, rooms, documents, landing pages and text blocks; CRM for Locations (prospects and customers), deals, tasks, timeline, e-mail, inquiries; statistics.
- **Location accounts (`/konto`):** a manager registers, asks access to an entry or creates one in a wizard; the owner accepts the first publication.
- **Eventlokale crawler:** kept for internal working data (D11, D12).

## What it will do (settled, see `docs/rpi-cycles.md`)

- Event types (Firmenanlass, Seminar, Geburtstag, Apéro, Hochzeit …), catering, technology and guest number as filters; layouts shown on the detail page, no layout filter (D4, E5).
- URLs carry the search term "Eventlokale": `/eventlokale` (listing), `/eventlokale/{kanton}` and `/eventlokale/{kanton}/{ort}` (landing pages), `/eventlokal/{venue}` (detail page), `/eventlokal-in-meiner-naehe`. No redirects, since nothing is live yet. The competitor's name, domain and logo never appear in titles, meta, copy or structured data (F5, 2026-11-07).
- **Free + Pro at CHF 199 a year, final, shown without a VAT line (D24, D25).** Free: a basic entry, 6 photos, 1 room, inquiries by e-mail. Pro: more photos, rooms, PDFs and video embeds, ranking above Free, visible contact data, last-minute offers, a detailed monthly report. Invoiced by hand; a lapsed Pro entry falls back to Free and stays online (D2).
- **Prepared entries.** The platform prepares an entry from the venue's own website; the manager gets a private preview link, claims it, confirms the photos they have rights to, and publishes. No photo is public without a rights record. Until then Google Places photos and a neutral placeholder fill the gap (D12, D16).
- **Brand:** indigo and vermilion, Hanken Grotesk, an SVG wordmark (D23). Hosted at Infomaniak in Switzerland with a Neon database in Frankfurt (D26).
- **No Hochzeit landing pages:** Hochzeit is an event type only (D31).
- **One venue per inquiry** at launch; later one brief to up to five named venues (D3, D17).
- **Honest measurement.** A qualified inquiry is a real, reachable organizer with a plausible event, delivered to the venue. It is not a booking and reports never say so (D19).

## Facts the owner holds

Every product decision is settled (D23 to D32 settled the former open questions). Only facts are left, each with a placeholder:

| Fact | Until it arrives |
|---|---|
| Operator name and address | `TODO(owner)` on the Impressum and Datenschutz pages; launch is refused while it is there (D27) |
| Infomaniak and Neon accounts | F15 documents the setup |
| Venue spreadsheet for D1(b) | F14 is built and tested on a fixture |
| Contact list for the one-to-one pilot | After launch (D30: no bulk mail) |

Since 2026-11-07 the PO and the tech lead decide scope, priorities and defaults together; the owner has delegated them and is not asked. The owner still holds real-world facts (the operator's identity, the host account, the venue spreadsheet, the contact list) and deploys. Where a fact is missing, the site ships a marked placeholder.

## Roadmap (proposed order)

1. The "du" flip first (F7a), then F3 to F8: vocabulary, filters, brand, copy and landing pages (event wording and look).
2. Wave G, launch-critical: rights records and takedown, own-site importer, preview link and claim, enquiry quality, funnel scorecard, tiers with term and expiry, monthly report, outreach tracking, launch regions, Free/Pro page.
3. F13 reverse match, last-minute offers, video embed, organizer feedback.
4. F15 production setup, F16 launch verification. Deploys are the owner's.
5. After launch: the 100-account outreach pilot (CMO §6.5), then the first 10 paying venues as the gate (CMO §11).

## Parked

Online payment (Stripe/TWINT), promo-video renderer and social kit, partner syndication, French/Italian/English, reviews of our own, a named-competitor comparison page, passkeys for owners.

## Principles

- Keep it simple. Two staff users at most, no privacy machinery beyond what the law needs, no extreme-input engineering.
- Say only what is live. A feature is advertised when its entitlement is on; an unchecked entry is labelled "Noch nicht vom Betreiber bestätigt".
- No image, text or data from another portal is published. Rights come before reach.
- The PO and the tech lead decide scope together. Deploys are the owner's alone.
- One write path: every change to an entry goes through the same commands, whoever makes it.
