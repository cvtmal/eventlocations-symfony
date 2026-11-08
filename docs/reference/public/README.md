# Public design reference

The target look of the public site (`docs/rpi-cycles.md` S15). Every public UI cycle compares its pages with these boards at 390 and 1440 px.
The back office keeps `docs/reference/admin/`.

- **Status:** the sister site drew these boards (approved 2026-10-31). F6b `brand-assets` (2026-11-07) redrew every board in the D23 look
  (`docs/brand.md`: indigo, vermilion, cool neutrals, Hanken Grotesk, the SVG lockup) with F3's event vocabulary, and checked them against the
  live pages at 390 and 1440 px. The design system is published privately: https://claude.ai/artifact/62CFPCBUmi8R7JwJJd4woV.
  F11 `portal-event` (2026-11-08) redrew `More` and `Detail` with F7b's taxonomy labels (groups "Art der Location", "Räume und
  Infrastruktur", "Rund um die Location", and the reworded terms of `setting`, `building`, `infrastructure` and `surroundings`), as
  live since F7b `event-copy-de`.
- **Files:** `canvas.json`, one `*.dc.html` per board and width, `assets/` (the logo, the dark logo for the mail, the font, drawn photos,
  icons, the canton map), `tokens.json`, and one PNG per board and width. The `.dc.html` files are static HTML with inline styles; open them
  in a browser, or read them for markup, values and copy.
- **Boards are not code.** Inline styles are the canvas format, and the CSP forbids them. Build from Sass partials and the sprite. All venues
  are fictional, photos are drawn placeholders, and bracketed text marks a fact that does not exist yet.
- **Rendering the PNGs:** full page at scale 1 in Chromium (the tools image of `make lighthouse`), e.g. with puppeteer-core from its global
  `lighthouse` install.

## Boards

`<Board>.png` is 1440 px, `<Board>-390.png` is 390 px. The home board is `Main` at 1440 and `Home-390` at 390.

| Board | Shows |
|---|---|
| `Tokens` | Colours with their contrast ratios, type, spacing, radii, shadow, every base component with its states, focus ring |
| `Header` | Header with count 0 and 3 (Locations, Regionen, In der Nähe, Merkliste, "Location eintragen"), menu, footer |
| `Main` / `Home-390` | Home page |
| `Listing` | Listing with real-data and rich cards, pagination, related links, empty state |
| `Canton` | Canton landing page |
| `Filterbar` | Bar with four triggers (Region, Anlass, Anzahl Gäste, weitere Filter; "Gäste" and "Filter +" at 390), active chips with the fading end at 390, "weitere Filter (N)" pill, the Anlass overlay |
| `Region` | Region overlay: tabs, list, radius and pin; map states |
| `Guests` | Guest overlay with one number input ("z.B. 80"), filled and focused |
| `More` | "Weitere Filter" with F3's groups (Art der Location, Lage, Räume und Infrastruktur, Mobiliar, Technik, Ausstattung ...), sticky footer |
| `DetailReal` | The shape of most real venues |
| `Detail` | Every section (facts, "Angaben zur Location", Räume, Häufige Fragen, Standort, Kontakt, Dokumente), action card, mobile bar |
| `DetailDialogs` | Gallery, room dialogs, share fallback, phone page |
| `Inquiry` | Form, errors, confirmation |
| `Merkliste` | Filled, toasts, empty, full notice |
| `Legal` | Datenschutz (a draft in "du"; operator facts are `[TODO]`, D27) |
| `Error404` | 404, with 410 and 500 wording |
| `Mail` | Guest copy and venue mail, with the indigo header band (F6b) |

## Where the live pages still differ (2026-11-07)

The boards show the target. Layout, colour, type and logo match the live pages at both widths; these differences are known and belong to
later cycles:

- **Copy (F7b done, F8 and F9 open):** F7b replaced the wedding wording of headings, footer, home, error pages and Datenschutz (operator facts
  are `TODO(owner)` placeholders, D27; processors per D26). Still open: the canton, town and building landing headings and intros (F8) and
  the inquiry's Anlass list (F9). The `More` and `Detail` boards draw F7b's taxonomy labels since F11.
- **Routes (F5):** board labels use `/eventlokale`, `/eventlokale/{kanton}`, `/eventlokale/{kanton}/{ort}`, `/eventlokal/{slug}` and
  `/eventlokal-in-meiner-naehe`; the live app has them since F5.
- **Content:** the boards keep curated fictional venues; the demo data is lorem-like. The Detail board draws a Google rating box, which the live
  page shows only with a Maps key. Wunschtermin is the browser's native date input live.

## Tokens

`tokens.json` and `assets/styles/abstracts/_tokens.scss` are the source; `docs/brand.md` has the table and the main contrast pairs, and
`tests/Unit/Shared/ContrastTokensTest.php` holds every pair.

**Type** (`≥ 768` / `< 768`): `display` 52/58 and 36/44, `h1` 40/48 and 30/38, `h2` 26/34 and 24/32, `h3` 18/26, `body` 16/26, `small` 14/22,
`button` 14/20, `button-large` 16/20, `tag` 13/18 (the smallest text). Headings are 700. Hanken Grotesk.

**Spacing:** 4, 8, 12, 16, 24, 32, 40, 64. **Radii:** `radius-small` 12, `radius-image` 24, `radius-card` 40, `radius-pill`.

## Decisions that carry over from the sister site's boards

- **Selected state**, one language everywhere: `accent-soft` ground, 2 px `accent-strong` edge, `ink` text, a check or ×. It applies to chips,
  tiles and the selected event chip.
- **No saturated colour behind text** except the primary button and the count badge in `accent-strong`; `accent` (vermilion) never behind text.
- **Overlays:** one ground, `overlay-ground`, with circles in `overlay-circle`. In Weitere Filter each group is a white card.
- **Canton map:** `map-line` separates land from the ground. `map-hover` (the accent) and `map-lake` differ by hue; lakes are not interactive,
  and the tooltip names the canton.
- **Card with a carousel:** 16 px padding with `radius-image` 24 inside `radius-card` 40. Photo arrows only from 768 px; the phone swipes.
- **Elevation:** cards have no shadow. Only floating layers carry `shadow-float`. Dialogs have no shadow; the backdrop stays `rgb(0 0 0 / .6)`.
- **Detail:** the hero is inset (24 px, phone 16). The action card sits below the sticky nav at `top: 92px`. `_similar_venues` has no heart.
- **Mail:** the system font stack, the indigo header band with the dark logo and a short vermilion bar, a pill button; the band keeps its colour
  in dark mode.
