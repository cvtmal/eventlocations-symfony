# Brand

F6's brand is the brand (D23): indigo, vermilion, cool neutrals, Hanken Grotesk and the SVG wordmark. F6 `brand-design` built it; F6b
`brand-assets` polished the lockups, the icon set, the share cards, the mail header and the print variant, and redrew the boards in
`docs/reference/public/`. A later brand change is still a swap of the files below and nothing else. The design system is also published
privately as an artifact (https://claude.ai/artifact/62CFPCBUmi8R7JwJJd4woV).

## Colour and type

- **Name:** `deine-eventlocation.ch` (D9). Tagline: "Eventlocations in der ganzen Schweiz, direkt bei der Location anfragen."
  (`layout.footer.tagline`; the default share card says the same).
- **Palette:** one primary, one accent, cool neutrals, in `assets/styles/abstracts/_tokens.scss`.

  | Token | Value | Role |
  |---|---|---|
  | `--c-accent-strong` (+ `-hover` `#2d3190`, `-active` `#232673`) | `#3a3fb0` indigo | The primary: buttons, links, selected edges, icons, the logo's badge, the ground of the share cards and of the mail header |
  | `--c-accent-soft` | `#e6e8fb` | Selected ground, tier badge; only `--c-ink` on it |
  | `--c-accent` | `#e0501a` vermilion | The accent: the logo's spark, the bar of the mail header and the cards, the home category icons, the canton hover. Never behind text, never text under 24 px |
  | `--c-surface` / `-raised` / `-alt` | `#f6f7fb` / `#fff` / `#eceef7` | Page ground, cards and footer, alternating bands |
  | `--c-ink` / `--c-ink-muted` | `#262b3f` / `#5a6078` | Text |
  | `--c-line-soft`, `--c-placeholder-ground`, `--c-overlay-*` | `#d8dcec`, `#dde1f0`, `#eceef7` and `#dfe3f3` | Decorative lines, the photo placeholder, overlay grounds |
  | status (`error`, `success`) and `disabled` | `error` and `success` as before; `disabled-ground` `#e3e6f0`, `disabled-ink` `#5a5f77` | Functional colours keep their meaning |

  Main contrast pairs (WCAG 2.x; `tests/Unit/Shared/ContrastTokensTest.php` holds all of them): ink on surface / raised / alt 13.1 / 14.0 / 12.1;
  muted on the same 5.8 / 6.2 / 5.4; `on-accent` on `accent-strong` 8.3; `accent-strong` as a link on surface / raised / alt 7.8 / 8.3 / 7.2;
  `accent` as an icon on the three grounds 3.7 / 3.9 / 3.4 (needs 3).
- **Typeface:** Hanken Grotesk (OFL-1.1), one variable file, self-hosted. Its average advance is within 1 % of the former typeface at 400 and
  700, so no line wraps differently. The logo's letters are outlines of its Bold, so the logo never waits for the font.
- **Back office:** keeps its own neutrals (`assets/styles/admin/_tokens.scss`); its accent, its links and the tint of the `pink` tone (the tone
  key is historical, it renders `--a-brand-bg`) follow the brand. Its sidebar (and the one of `/konto`) shows the mark above the wordmark.

## The mark and the lockups

**The mark** is an indigo rounded square (32 units, radius 8) with a white location pin and a vermilion four-point spark in the pin's head.
The pin is drawn at 1.2 times the Lucide `map-pin` (`translate(16 15.8) scale(1.2) translate(-12 -11.95)`), so it still reads as a pin at
16 px; the spark is a dot there and a spark from 32 px. `public/brand/favicon.svg` is the mark; every lockup and the inline logo draw the same
pin and spark (`BrandFilesTest` compares the transform and both paths).

| File | Lockup | Ground | Use |
|---|---|---|---|
| `logo.svg` | horizontal, 286 × 32 | light (surface, white, light photos never) | The header and footer (inline copy: `templates/components/_logo.html.twig`), documents, partner sites |
| `logo-dark.svg` | horizontal, 286 × 32 | the primary or dark (`ink`) | Mail header (as `mail-logo.png`), share cards, dark slides. White badge, indigo pin, white name, `.ch` at 72 % white |
| `logo-stacked.svg` | stacked, 243.6 × 84.9 (mark 56 over the name) | light | Square places: profile pictures with room, a sign, a flyer's foot, the cover of a document |
| `logo-stacked-dark.svg` | stacked | the primary or dark | The same on a dark ground |
| `logo-mono.svg` | horizontal | white paper | One colour (black, the pin white): print, fax, stamps, engraving. The printed venue page draws the inline logo in this variant (`@media print` in `assets/styles/components/_logo.scss`) |
| `logo-stacked-mono.svg` | stacked | white paper | One colour, stacked |

**Clear space.** Keep free, on every side, half the height of the mark (16 units in the horizontal lockup: 12 px at the header's 24 px). No
text, edge or other logo enters it; the header's padding already holds it.

**Minimum sizes.**

| What | Screen | Print |
|---|---|---|
| Horizontal lockup | 20 px high (the phone header's 1.25 rem; about 179 px wide) | 30 mm wide |
| Stacked lockup | 48 px high | 18 mm wide |
| Mark alone | 16 px (favicon) | 5 mm |

Below the minimum of a lockup use the mark alone.

**Do**

- Use the files as they are, scaled proportionally, on the ground their row names.
- Use the mark alone where the name is already there (favicon, app icon, the sidebar above a text wordmark).
- Use the mono variant whenever only one ink is available.

**Don't**

- Recolour the badge, the spark or the name, add a shadow, an outline or a gradient, or stretch or rotate any part.
- Set the name in live text instead of the outlines, or in another typeface.
- Put a lockup on a photo (S15) or the light lockup on the primary; put the spark or the accent behind text.
- Rearrange the parts (the mark right of or under the name in the horizontal lockup), or cut `.ch`.
- Use eventlokale.ch's or hochzeitslocation.ch's names, logos, colours or photos anywhere (D11, D12).

## Icons

`favicon.svg` is the mark. The PNGs and the ICO are renders of it (`make brand-png`, below), so a new mark is one file and one command.

| File | Size | Notes |
|---|---|---|
| `favicon.svg` | vector | Browsers that take SVG favicons (most); the back office and `/konto` sidebars show it at 32 px |
| `favicon.ico` | 16, 32, 48 px | PNG entries; older browsers and crawlers that ask for `/favicon.ico` by name |
| `apple-touch-icon.png` | 180 px | The mark on a full indigo ground, no transparency (iOS rounds the corners and shows transparency as black) |
| `icon-192.png`, `icon-512.png` | 192, 512 px | The manifest's `any` icons; `icon-512.png` is the Organization's `logo` in the JSON-LD |
| `icon-maskable-192.png`, `icon-maskable-512.png` | 192, 512 px | The manifest's `maskable` icons: a full indigo ground, the pin inside the central 80 % circle that every mask keeps |
| `site.webmanifest` | | Name, colours (the page ground) and the icons |

## Share cards

Every page without a photo of its own shares `og-default.png` (1200 × 630; `og:image`, `twitter:image`, `summary_large_image`, in
`templates/seo/_meta.html.twig`); a venue page with a photo keeps its photo. The cards are SVG first: `templates/brand/og_card.svg.twig` draws
an `App\Shared\ValueObject\OgCard` on the primary, with `logo-dark.svg` top left, the vermilion bar, and white text.

| Shape | Built by | Text | Who fills it |
|---|---|---|---|
| default | `OgCard::default($tagline)` | the tagline, up to three lines | done: `og-default.png` |
| venue | `OgCard::venue($place, $name, $facts)` | place above, name in up to two lines, facts below (e.g. "20–120 Personen · Seminar, Apéro"); the mark on the right | F7b, for a venue without a photo |
| landing | `OgCard::landing($eyebrow, $heading, $facts)` | e.g. "Eventlokale", "Seminarräume im Kanton Zürich", "48 Locations"; the mark on the right | F8 |

SVG does not wrap text, so `OgCard` breaks the title by characters (22 a line at 64 px, the default card 26) and ends an overflow in "…";
eyebrow and facts are one line of at most 44 characters. `make sf c="app:brand:og-card --variant=venue --eyebrow=… --title=… --facts=… --out=var/brand/x.svg"`
writes one card. Social networks do not take SVG, so a card needs a PNG render: `make brand-png` does it for the default card. Rendering
per venue or per landing page at runtime would need a rasteriser the server does not have (GD only, no Chromium); F7b and F8 choose between
prerendering a fixed set (the landing pages are a finite list) in the tools container and keeping `og-default.png`.

## Mail header

`templates/emails/layout.html.twig`: the card starts with an indigo band (`#3a3fb0`, radius 24 at the top, none below 620 px) holding
`mail-logo.png` (the dark lockup at twice the displayed 215 × 24 px, on an indigo ground of its own) and a 32 × 3 px vermilion bar. The
layout gives the band no dark-mode override, and the PNG carries its own indigo, so the logo needs no white plate even where a client
recolours backgrounds. The image's alt text is white, so a mail with images blocked still shows the name on the band.

## Rendering the PNGs

`make brand-png` writes `var/brand/og-default.svg` (`app:brand:og-card`), then renders every PNG of `public/brand/` and `favicon.ico` with the
Chromium of the tools image (`docker/lighthouse/render-brand.mjs`; tooling only, no runtime dependency). Run it after any change to
`favicon.svg`, `logo-dark.svg`, the card template or the tagline, and commit the files it wrote.

## Swap checklist

Replace these and nothing else. After each step `make test` says what is left: `ContrastTokensTest` (the pairs), `MailLiteralsTest` (a mail colour that
is not a token), `BrandFilesTest` (names and sizes of `public/brand/`, the theme colour, the manifest, the site name, the mark in every lockup).

| What | Files | Notes |
|---|---|---|
| Colours | `assets/styles/abstracts/_tokens.scss`; the back office: `--a-accent`, `--a-accent-bright`, `--a-link`, `--a-link-hover`, `--a-brand-bg`, `--a-brand` in `assets/styles/admin/_tokens.scss` | Keep the names. `--c-accent-strong` is the primary and the only saturated ground behind text; `--c-accent` is never behind text |
| Colours as literals | `templates/emails/layout.html.twig` and `_blocks.html.twig` (a mail cannot read CSS variables); `templates/brand/og_card.svg.twig`; `theme-color` in `templates/layout/_brand_head.html.twig`; `theme_color` and `background_color` in `public/brand/site.webmanifest`; the colours inside `public/brand/*.svg`; `PRIMARY` in `docker/lighthouse/render-brand.mjs` | Mails: ground = `surface-alt`, text = `ink`, header band, button and quote edge = `accent-strong`, bar under the logo = `accent`, muted = `ink-muted`, rule = `line-soft`. The dark-mode block of the layout keeps its neutrals (`#121212`, `#272727`, ...); its link tint (`#c7caff`) is a light tint of the primary and follows it |
| Typeface | `assets/fonts/<name>-latin-wght-normal.woff2` and its licence file; `assets/styles/base/_fonts.scss` (`@font-face`, the Arial fallback's metrics); `--font-sans` in the public tokens and `--a-font` in the admin tokens; the preload in `templates/layout/_brand_head.html.twig`; `FONT` in `docker/lighthouse/render-brand.mjs` | At most two font files (S11), never a request to a font host (CSP). Measure the fallback: `size-adjust` from the average advance of a German sentence against Arial, `ascent-override` and `descent-override` from the font's `hhea` divided by `size-adjust` |
| The logo on the page | `templates/components/_logo.html.twig` (the art between its two comments), size in `assets/styles/layout/_header.scss` and `_footer.scss`, colours (and the print colours) in `assets/styles/components/_logo.scss` | Inline SVG, no `style` attribute (CSP). Keep `viewBox`, `width` and `height` in step with the artwork (`BaseLayoutTest` pins 286 × 32), and `id="logo-art"` on the wrapper of the art: the header prints it once, and the footer's `<use href="#logo-art">` points at it. Check the header's width at 768 px (the nav needs about 480 px beside the logo) |
| Lockups, mark, icons, share card, mail logo | `public/brand/`: the six `logo*.svg`, `favicon.svg`, then `make brand-png` for `favicon.ico`, `apple-touch-icon.png`, `icon-192.png`, `icon-512.png`, `icon-maskable-192.png`, `icon-maskable-512.png`, `og-default.png`, `mail-logo.png` | The head links them in `templates/layout/_brand_head.html.twig`; the mail layout attaches `mail-logo.png` through the Twig path `@brand` (`config/packages/twig.yaml`); `bin/build-release` checks that file ships |
| The name | `site.name` in `config/services.yaml`, `layout.site_name`, `layout.logo_alt` and `layout.footer.copyright` in `translations/messages+intl-icu.de.yaml`, the issuer in `config/packages/scheb_2fa.yaml`, `name` in `public/brand/site.webmanifest` | `BrandFilesTest` fails when they differ |
| Photo placeholder | the map in `templates/components/_photo_placeholder.html.twig`; icons are symbols of `assets/icons/sprite.svg` | See below |
| Boards | `docs/reference/public/` (`assets/logo.svg`, `assets/logo-dark.svg`, the font copy, the colours inline) | Boards are not code; redraw them, then their PNGs |

## The photo placeholder (D12, follow-up 1)

A venue without a public photo shows `templates/components/_photo_placeholder.html.twig`: the placeholder ground, one soft circle and one icon in the
primary. It is decorative (`aria-hidden`, no text, no link), so it adds no copy.

```twig
{{ include('components/_photo_placeholder.html.twig', {event_type: 'seminar', class: 'my-box'}, with_context = false) }}
```

- `event_type` is a plain string, the code of an event type. The partial maps a code to a Lucide icon (`seminar` to a lectern, `birthday` to a cake,
  `apero` to a glass, ...); an unknown code, an empty one or `null` gets the generic sparkle. Add or rename a code in that one map. F5 and F7b feed the
  parameter: `search/_card` already accepts `event_type` and passes it on.
- `class` is for the place it stands in. The component sets no height and no radius; the place does (a card's `.card__placeholder` passes its own radius on).
- Used on: the listing, Merkliste and home cards (`search/_card`), the similar-venues cards, the room cards of the detail page, the home hero without a
  spotlight photo, and, where the detail page has no hero, a short band (`.venue-placeholder`) in the hero's place.
- A new icon needs a `<symbol>` in `assets/icons/sprite.svg` (sorted, no presentation attributes) and a line in `assets/icons/LICENSES.md`.

`public/brand/` holds plain files, not AssetMapper assets, because browsers and crawlers ask for some of them by a fixed name; the head's CSP
therefore allows `manifest-src 'self'`.
