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

The code is the wedding site's, renamed and stripped (cycle `fork-baseline`, 2026-11-06). Since F3 to F8 it is an event site in look, vocabulary, filters, URLs and wording; no wedding wording is left in public output (`NoWeddingCopyTest`). The operator's name, address and UID are `TODO(owner)` placeholders, and production refuses to boot while any remain (D27).

- **Public:** home page with a search by canton, event type and guest number; the listing at `/eventlokale` with filters for event type, guests, catering and more; one detail page per venue with photos, rooms, documents and map, landing pages per canton, town, event type and event type × canton (`/eventlokale/{kanton}`, `/eventlokale/{anlass}`, `/eventlokale/{kanton}/{anlass}`; none for Hochzeit), the Merkliste without login, an inquiry form on every venue, SEO basics, own cookieless statistics.
- **Back office (`/admin`, staff only, 2FA):** CMS for entries, photos, rooms, documents, landing pages and text blocks; CRM for Locations (prospects and customers), deals, tasks, timeline, e-mail, inquiries; statistics.
- **Location accounts (`/konto`):** a manager registers, asks access to an entry or creates one in a wizard; the owner accepts the first publication.
- **Event vocabulary (F3):** 17 event types (Firmenanlass, Seminar, Tagung / Konferenz, Workshop, Sitzung / Meeting, Produktpräsentation, Generalversammlung, Teamevent, Weihnachtsfeier, Vereinsanlass, Apéro, Geburtstag, Familienfeier, Hochzeit, Party, Konzert, Ausstellung), catering and technology as their own groups; staff and managers edit them, the detail page shows them under "Geeignet für". Not yet filterable (F5).
- **Rooms (F4):** six layouts per room (Bankett, Seminar/Parlament, U-Form, Block, Konzert/Reihen, Apéro/Stehempfang), each with a guest range; the room's range follows its layouts. Shown on the detail page, not a filter (D4).
- **Own-site importer (G3, staff only):** reads a venue's own website safely and stores facts and photo candidates privately; nothing reaches a public page.
- **Photo rights (G1):** a photo is public only with a rights record. Staff uploads and imports are granted at once; a manager's photos wait until the manager accepts the rights declaration in `/konto`. Revoking deletes the photo everywhere.
- **Preview links (G5):** staff issue a private link (with a QR code for letters) to a prepared entry. It shows the entry as it would look, with the banner "Noch nicht vom Betreiber bestätigt" and only photos with a rights record; it is never indexed and can be revoked. Taking over the entry from the preview comes with G6.
- **Tiers (F12):** Free and Pro are data that staff edit in the CMS («Tarife»). Free: 6 photos, 1 room, 1 video, 1 PDF, 3 event types, no phone or website shown. Pro (CHF 199 a year): 60 photos, 20 rooms, 5 videos, 5 PDFs, all event types, website and phone (behind a click), the label «Top-Eintrag» and ranking above Free. The limits are enforced in the CMS and in `/konto`. Paid terms and expiry come with G9.
- **Reports (G2):** anyone can report a photo, an entry or wrong facts at `/inhalt-melden`; a reported photo is hidden until staff decide in `/admin/meldungen`.
- **Photo backup (F15b):** the photos are copied every night, encrypted, to Infomaniak Object Storage in Switzerland; launch is refused without a backup less than 26 hours old.
- **Funnel scorecard (G8, `/admin`):** counts previews, claims, publications, delivered inquiries and paid venues per campaign and week, with no personal data.
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

Every product decision is settled (D23 to D54). Only facts and the owner's own production steps are left, each with a placeholder or a runbook:

| Fact | Until it arrives |
|---|---|
| Operator name and address | `TODO(owner)` on the Impressum and Datenschutz pages; launch is refused while it is there (D27) |
| Infomaniak and Neon accounts, first setup and first deploy | Runbook in `docs/operations.md` (F15); deploys stay off until the owner sets the repository variable `DEPLOY_ENABLED` |
| Backup schedule and Sentry cron monitor | Runbook in `docs/operations.md`; the database backup runs by hand until the owner schedules it |
| Infomaniak Object Storage credentials for the media backup (F15b) | Placeholders; launch is refused without a backup less than 26 hours old |
| Once a year: delete the report mails (G2) older than three years in the contact mailbox (D36) | Note in `docs/operations.md` |
| Venue spreadsheet for D1(b) | F14 is built and tested on a fixture |
| Contact list for the one-to-one pilot | After launch (D30: no bulk mail); F14 builds the import |
| Google photos and rating: check Google's terms, then switch them on | Both off; the placeholder shows (D48) |

Since 2026-11-07 the PO and the tech lead decide scope, priorities and defaults together; the owner has delegated them and is not asked. The owner still holds real-world facts (the operator's identity, the host account, the venue spreadsheet, the contact list) and deploys. Where a fact is missing, the site ships a marked placeholder.

## Cycles at a glance

_Status as of 2026-11-08, kept by the PO after every merge. The cycles themselves are in `docs/rpi-cycles.md`: F-cycles in the overview table and under "Cycles", G-cycles in Wave G §3._

**Done:** F0 `fork-baseline` (copy of the wedding site, renamed and stripped) · F0b `fork-docs` (docs) · F1 `owner-decisions` (D1–D12) · F7a `du-flip` ("du" everywhere) · F6 `brand-design` (colours, typeface, logo, photo placeholders) · F3 `event-vocabulary` (17 event types, catering, technology) · G3 `site-import` (reads a venue's own website, internal) · G8 `funnel-scorecard` (funnel counts in `/admin`) · `rules-condense` (developer notes halved) · G1 `rights-grants` (no photo public without a rights record) · F15 `prod-setup` (deploy to an Infomaniak VPS + Neon, off until the owner enables it) · F4 `room-layouts` (six seating layouts per room) · F6b `brand-assets` (logo lockups, icons, share-card templates, 34 boards in the new look) · G5 `preview-link` (private preview link with QR, issued and revoked in the CMS) · F5 `filter-event` (event-type and guest filters, `/eventlokale…` URLs) · G2 `takedown-form` (report a photo or entry; reported photos hidden until decided) · F15b `media-backup` (nightly encrypted copy of the photos to Infomaniak Object Storage) · F12 `business-model` (Free + Pro as data, limits enforced) · `takedown-form-finish` (privacy paragraph on reports, D36 deletion job) · F8 `landing-event` (event and event × canton landing pages, no Hochzeit) · F7b `event-copy-de` (all German copy, legal pages with the processors, operator as placeholder) · F5b `guests-rooms` (one capacity everywhere: venue or rooms; card placeholder per event type) · F11 `portal-event` (`/konto` with rooms, PDFs and event types, Free limits with «Pro anfragen») · G9 `subscription-term` (Pro terms with expiry and reminders, free pilot terms, voiding a payment) · G12 `launch-regions` (ZH, AG, ZG, BE first; thin and unchecked pages stay out of Google) · G6 `claim-verify` (venue claims its entry by a code to its own domain, confirms photo rights; unconfirmed label on the detail page) · G11 `outreach-tracking` (personal and postal contacts, print list with short links, opt-out page) · `claim-verify-finish` (a verified claim sets «Fakten geprüft»).

**Built, PR open:** F9 `inquiry-event` (inquiry form with event fields) · `places-photos` (Google photos and rating, built but off, D48) · G15 `video-embed` (click-to-load videos).

**In a lane:** G13 `tier-pages` ("Eintragen / Preise") · F14 `content-onboarding` (venues in, as drafts; contact-list import) · G10 `owner-report` (monthly report; the qualified figure follows G7).

**Launch-critical, not started:** G7 `enquiry-quality` (real vs. spam, delivery status) · F16 `launch-verification` (final check).

**After launch-critical:** F13 `multi-inquiry` (one request to up to 5 venues) · G14 `last-minute-offers` (paused) · G16 `organizer-feedback`.

## Roadmap (proposed order)

1. Event wording and look: "du" (F7a), brand (F6) and vocabulary (F3) are done; filters and URLs (F5), room layouts (F4), copy (F7b), landing pages (F8) and the brand polish (F6b) follow.
2. Wave G, launch-critical: rights records and takedown, own-site importer, preview link and claim, enquiry quality, funnel scorecard, tiers with term and expiry, monthly report, outreach tracking, launch regions, Free/Pro page.
3. F13 reverse match, last-minute offers, video embed, organizer feedback.
4. F15 production setup (done), F16 launch verification. Deploys are the owner's.
5. After launch: the 100-account outreach pilot (CMO §6.5), then the first 10 paying venues as the gate (CMO §11).

## Parked

Online payment (Stripe/TWINT), promo-video renderer and social kit, partner syndication, French/Italian/English, reviews of our own, a named-competitor comparison page, passkeys for owners.

## Principles

- Keep it simple. Two staff users at most, no privacy machinery beyond what the law needs, no extreme-input engineering.
- Say only what is live. A feature is advertised when its entitlement is on; an unchecked entry is labelled "Noch nicht vom Betreiber bestätigt".
- No image, text or data from another portal is published. Rights come before reach.
- The PO and the tech lead decide scope together. Deploys are the owner's alone.
- One write path: every change to an entry goes through the same commands, whoever makes it.
