# Public design reference

The target look for the public site (`docs/rpi-cycles.md` S15, BRD-1). From #35 on, every public UI cycle compares its pages with these boards at 390 and 1440 px. `docs/reference/*.jpg` stays only as the record of behaviour. The back office keeps `docs/reference/admin/`.

- **Live design:** canvas https://claude.ai/artifact/5mAkCeDjP2zympXDYqzyrT, design system https://claude.ai/artifact/7KTZibKEpKkofJg6Xjwsb2. If they differ from this folder, the live design wins; copy it back here.
- **Files:** `canvas.json`, one `*.dc.html` per board and width, `assets/`, `tokens.json`, and one PNG per board and width. The `.dc.html` files need the canvas runtime, so read them for markup, values and copy.
- **Status:** drawn in #34 and approved by the owner on 2026-10-31 (Q15). #41 repeats the sign-off on the running app.
- **Boards are not code.** Inline styles are the canvas format, and the CSP forbids them. Build from Sass partials and the sprite. All venues are fictional, photos are drawn placeholders, and bracketed text marks a fact that does not exist yet.

## Boards

`<Board>.png` is 1440 px, `<Board>-390.png` is 390 px. The home board is `Main` at 1440 and `Home-390` at 390.

| Board | Shows | Cycle |
|---|---|---|
| `Tokens` | Colours, type, spacing, radii, shadow, every base component with its states, focus ring | #35 |
| `Header` | Header with count 0 and 3, menu, footer | #35 |
| `Main` / `Home-390` | Home page with S15's decisions applied | #36 |
| `Listing` | Listing with real-data and rich cards, pagination, related links, empty state | #37 |
| `Canton` | Canton landing page | #37 |
| `Filterbar` | Bar, active chips, "weitere Filter (N)" pill | #37 |
| `Region` | Launch shape without the Ort tab, then tabs, list, radius and pin; map states | #38 |
| `Guests` | Number input, nine bubbles, one pressed | #38 |
| `More` | Full data, launch shape, sticky footer | #38 |
| `DetailReal` | The shape of most real venues; draw this first | #39 |
| `Detail` | Every section, action card, mobile bar | #39 |
| `DetailDialogs` | Gallery, room dialogs, share fallback, phone page | #39 |
| `Inquiry` | Form, errors, confirmation | #39 |
| `Merkliste` | Filled, toasts, empty, full notice | #37 |
| `Legal` | Datenschutz | #40 |
| `Error404` | 404, with 410 and 500 wording | #40 |
| `Mail` | Guest copy and venue mail | #40 |

## Tokens

`tokens.json` is the source, with every measured ratio in its `usage` notes. 35 pairs were checked against their limits and all pass.

| Token | Value | Use |
|---|---|---|
| `surface` / `surface-raised` / `surface-alt` | `#F8F6EE` / `#FFFFFF` / `#F0F1EB` | Page, cards, alternating sections |
| `ink` / `ink-strong` / `ink-muted` | `#484848` / `#000000` / `#6B6B66` | Text |
| `accent` | `#FF0096` | Icons, logo pin, text from 24 px; never behind text |
| `accent-strong` / `-hover` / `-active` | `#D1007A` / `#B4006A` / `#96005A` | Primary button, count badge, text links |
| `accent-soft` | `#FFE3F2` | Selected state and tier badge, with `ink` only |
| `on-accent` | `#FFFFFF` | Text on `accent-strong` (5.26:1) |
| `line` / `line-soft` | `#484848` / `#DEDFD6` | Input underline / decorative rule |
| `focus` | `#000000` | 2 px outline, 2 px offset; on a photo add a white halo, `box-shadow: 0 0 0 6px #fff` |
| `error` / `error-soft` | `#B3261E` / `#FBE9E7` | Errors |
| `success` / `success-soft` | `#1B6E45` / `#E2F1E8` | Confirmation |
| `disabled-ground` / `disabled-ink` | `#E6E6E0` / `#66665F` | Inactive buttons |
| `tier-ground` / `tag-ground` / `overlay-ground` | aliases of `accent-soft` / `surface-alt` / `surface-alt` | Badge, tag, overlays |
| `overlay-circle` / `placeholder-ground` | `#E6E8DF` / `#E3E1D6` | Decorative circles, photo while loading |
| `map-land` / `map-line` | `#FFFFFF` / `#8A8A82` | Canton fill, borders |
| `map-hover` / `map-selected` / `map-lake` | `#FF0096` / `#700044` / `#6B8AA0` | Hover, selected, lakes |
| `shadow-float` | `0 8px 32px rgba(72, 72, 72, .16)` | Floating layers only |

**Type** (`≥ 768` / `< 768`): `display` 52/58 and 36/44, `h1` 40/48 and 30/38, `h2` 26/34 and 24/32, `h3` 18/26, `body` 16/26, `small` 14/22, `button` 14/20, `button-large` 16/20, `tag` 13/18 (the smallest text). Headings are 700.

**Spacing:** 4, 8, 12, 16, 24, 32, 40, 64. **Radii:** `radius-small` 12, `radius-image` 24, `radius-card` 40, `radius-pill`.

## Decisions settled in #34

- **Type scale:** `display` covers the home hero only, at the board's 52/58 and weight 700. `h1` is a new 40/48 for page titles. `h2` takes the board's 26/34.
- **`on-accent`** is white, as the board draws it. `accent`'s usage note no longer lists buttons.
- **"Keine pinken Flächen hinter Text":** no saturated pink behind text, except the primary button and the count badge in `accent-strong`.
- **Selected state**, one language everywhere: `accent-soft` ground, 2 px `accent-strong` edge, `ink` text, a check or ×. It applies to chips, tiles and the pressed guest bubble.
- **Guest bubbles:** white with `ink` text. The percent map stays, but the phone needs `width: max(2.75rem, …)` (only bubble 20 changes, 34 to 44 px). A 1 px `line-soft` edge separates the overlaps.
- **Overlays:** the three tones are gone. One ground, `overlay-ground`, with circles in `overlay-circle`. In Weitere Filter each `.filter-more__group` is a white card instead of a rule.
- **Canton map:** five colours cannot all hold 3:1 with each other. `map-line` separates land from the ground (the fills are 1.14:1). `map-hover` and `map-lake` are equally light (1.01:1) and differ by hue; lakes are not interactive, and the tooltip names the canton. Every other pair is at least 3:1.
- **Card with a carousel:** 16 px padding with `radius-image` 24 inside `radius-card` 40, concentric at every width. Photo arrows only from 768 px, so #35 hides the real controls below that; the phone swipes.
- **Elevation:** cards have no shadow. Only floating layers carry `shadow-float`. Dialogs have no shadow; the backdrop stays `rgb(0 0 0 / .6)`.
- **Nav "Regionen":** links to `/#regionen` from #36. Until #36, `/` is still a 302, so #35's header links to `listing`.
- **"Location eintragen":** visual only for now, with no function behind it (Q19). The boards' `mailto:` href is superseded.

## For the build cycles

- **#35:** take a secondary button's hover and active ink from the `Tokens` board (`accent-strong-hover`, `accent-strong-active`), because `accent-strong` on `accent-soft` is 4.39:1. The menu button exists only below 768 px.
- **#36:** Spotlight cards keep the heart.
- **#37:** the phone bar scrolls sideways as today, and the Merkliste button shrinks to a 52 px heart with its `aria-label`. The shortened last item on `Filterbar-390` is a board trick; do not copy it. The hero bar straddles the band's lower edge on desktop only.
- **#38:** the launch shape of Weitere Filter comes from `OwnerFactsMapper`'s tables and #32's counts, not from the database, so check it against real data. `::placeholder` colour and real sticky scrolling could not be drawn. Aargau's tooltip overlaps Zürich as `region-filter.js` places it, so the tooltip has a pointer arrow.
- **#39:** the hero is inset (24 px, phone 16), not edge to edge. The action card sits below the sticky nav at `top: 92px` and no longer overlaps the hero. `_similar_venues` has no heart (DP-15). `InquiryType` has `rows: 7` while the phone draws 10, so give the textarea a CSS min-height. Wunschtermin stays native `type=date`. `venue-hero--empty` is not drawn.
- **#40:** the mail keeps the system font stack and the short accent line under the logo, with a pill button. The logo PNG is transparent, so dark mode needs a white plate behind it. The legal text on the board is the draft changed to "Sie", still for the owner's review.

## Owner's answers (2026-10-31)

- **Q16:** Figtree.
- **Q17:** a placeholder PNG for now. `assets/logo-hzl.png` is that placeholder.
- **Q18:** photos from Wix, downloaded once and served from our own site through #5's pipeline, never a live link to Wix.
- **Q19:** see "Location eintragen" above.
