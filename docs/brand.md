# Brand: the placeholder and where to swap it

The owner supplies the brand: logo, colours, typeface (D5). Until then the site carries a placeholder (cycle F6, `brand-design`). It is
complete (every page, mail, icon and share card has a brand), sober, accessible, and built so that a real brand is a swap of the
files below and nothing else. No layout was redesigned: the boards in `docs/reference/public/` are still the target for layout, no longer
for colour or type.

## The placeholder

- **Name:** `deine-eventlocation.ch` (D9).
- **Palette:** one primary, one accent, cool neutrals, in `assets/styles/abstracts/_tokens.scss`. The token names did not change, only the values.

  | Token | Value | Role |
  |---|---|---|
  | `--c-accent-strong` (+ `-hover` `#2d3190`, `-active` `#232673`) | `#3a3fb0` indigo | The primary: buttons, links, selected edges, icons, the logo's badge |
  | `--c-accent-soft` | `#e6e8fb` | Selected ground, tier badge; only `--c-ink` on it |
  | `--c-accent` | `#e0501a` vermilion | The accent: the logo's spark, the home category icons, the canton hover. Never behind text, never text under 24 px |
  | `--c-surface` / `-raised` / `-alt` | `#f6f7fb` / `#fff` / `#eceef7` | Page ground, cards and footer, alternating bands |
  | `--c-ink` / `--c-ink-muted` | `#262b3f` / `#5a6078` | Text |
  | `--c-line-soft`, `--c-placeholder-ground`, `--c-overlay-*` | `#d8dcec`, `#dde1f0`, `#eceef7` and `#dfe3f3` | Decorative lines, the photo placeholder, overlay grounds |
  | status (`error`, `success`) and `disabled` | `error` and `success` as before; `disabled-ground` `#e3e6f0`, `disabled-ink` `#5a5f77` | Functional colours keep their meaning; the disabled pair moved to the cool neutrals |

  Main contrast pairs (WCAG 2.x; `tests/Unit/Shared/ContrastTokensTest.php` holds all of them): ink on surface / raised / alt 13.1 / 14.0 / 12.1;
  muted on the same 5.8 / 6.2 / 5.4; `on-accent` on `accent-strong` 8.3; `accent-strong` as a link on surface / raised / alt 7.8 / 8.3 / 7.2;
  `accent` as an icon on the three grounds 3.7 / 3.9 / 3.4 (needs 3).
- **Typeface:** Hanken Grotesk (OFL-1.1), one variable file, self-hosted. It was picked because its average advance is within 1 % of the former
  typeface at 400 and 700, so no line wraps differently and no box changes size.
- **Logo:** a 286 × 32 lockup: an indigo rounded badge with a white pin and a vermilion spark, then `deine-eventlocation` in the ink and `.ch` in the
  primary. The letters are outlines (Hanken Grotesk Bold), so the logo never waits for the font.
- **Back office:** keeps its own neutrals (`assets/styles/admin/_tokens.scss`); its accent, its links and the tint of the `pink` tone (the tone key
  of pills and timeline icons is historical, it renders `--a-brand-bg`) follow the brand.

## Swap checklist

Replace these and nothing else. After each step `make test` says what is left: `ContrastTokensTest` (the pairs), `MailLiteralsTest` (a mail colour that
is not a token), `BrandFilesTest` (names and sizes of `public/brand/`, the theme colour, the manifest, the site name).

| What | Files | Notes |
|---|---|---|
| Colours | `assets/styles/abstracts/_tokens.scss`; the back office: `--a-accent`, `--a-accent-bright`, `--a-link`, `--a-link-hover`, `--a-brand-bg`, `--a-brand` in `assets/styles/admin/_tokens.scss` | Keep the names. `--c-accent-strong` is the primary and the only saturated ground behind text; `--c-accent` is never behind text |
| Colours as literals | `templates/emails/layout.html.twig` and `_blocks.html.twig` (a mail cannot read CSS variables); `theme-color` in `templates/layout/_brand_head.html.twig`; `theme_color` and `background_color` in `public/brand/site.webmanifest`; the colours inside `public/brand/*.svg` | Mails: ground = `surface-alt`, text = `ink`, button and quote edge = `accent-strong`, bar under the logo = `accent`, muted = `ink-muted`, rule = `line-soft`. The dark-mode block of the layout keeps its neutrals (`#121212`, `#272727`, ...); its link tint (`#c7caff`) is a light tint of the primary and follows it |
| Typeface | `assets/fonts/<name>-latin-wght-normal.woff2` and its licence file; `assets/styles/base/_fonts.scss` (`@font-face`, the Arial fallback's metrics); `--font-sans` in the public tokens and `--a-font` in the admin tokens; the preload in `templates/layout/_brand_head.html.twig` | At most two font files (S11), never a request to a font host (CSP). Measure the fallback: `size-adjust` from the average advance of a German sentence against Arial, `ascent-override` and `descent-override` from the font's `hhea` divided by `size-adjust` |
| The logo on the page | `templates/components/_logo.html.twig` (the art between its two comments), size in `assets/styles/layout/_header.scss` and `_footer.scss`, colours in `assets/styles/components/_logo.scss` | Inline SVG, no `style` attribute (CSP). Keep `viewBox`, `width` and `height` in step with the artwork (`BaseLayoutTest` pins 286 × 32), and `id="logo-art"` on the wrapper of the art: the header prints it once, and the footer's `<use href="#logo-art">` points at it. Check the header's width at 768 px (the nav needs about 480 px beside the logo) |
| Favicon, app icons, share card, mail logo | `public/brand/`: `favicon.svg`, `favicon.ico` (16, 32 and 48 px), `apple-touch-icon.png` (180 px, no transparency), `icon-192.png` and `icon-512.png` (the manifest and the Organization's `logo`; keep the glyph inside the central 80 %), `og-default.png` (1200 × 630, the Open Graph and Twitter card of every page without a photo), `mail-logo.png` (twice the displayed 215 × 24 px; mail clients do not show SVG), `logo.svg` (the standalone art, for other uses) | The head links them in `templates/layout/_brand_head.html.twig`; the mail layout attaches `mail-logo.png` through the Twig path `@brand` (`config/packages/twig.yaml`); `bin/build-release` checks that file ships |
| The name | `site.name` in `config/services.yaml`, `layout.site_name`, `layout.logo_alt` and `layout.footer.copyright` in `translations/messages+intl-icu.de.yaml`, the issuer in `config/packages/scheb_2fa.yaml`, `name` in `public/brand/site.webmanifest` | `BrandFilesTest` fails when they differ |
| Photo placeholder | the map in `templates/components/_photo_placeholder.html.twig`; icons are symbols of `assets/icons/sprite.svg` | See below |

## The photo placeholder (D12, follow-up 1)

A venue without a public photo shows `templates/components/_photo_placeholder.html.twig`: the placeholder ground, one soft circle and one icon in the
primary. It is decorative (`aria-hidden`, no text, no link), so it adds no copy.

```twig
{{ include('components/_photo_placeholder.html.twig', {event_type: 'seminar', class: 'my-box'}, with_context = false) }}
```

- `event_type` is a plain string, the code of an event type. The partial maps a code to a Lucide icon (`seminar` to a lectern, `birthday` to a cake,
  `apero` to a glass, ...); an unknown code, an empty one or `null` gets the generic sparkle. The map holds the codes of the baseline `suitable_for`
  group and a few likely ones; when F3's vocabulary is in, add or rename a code in that one map. F5 and F7b feed the parameter: `search/_card` already
  accepts `event_type` and passes it on.
- `class` is for the place it stands in. The component sets no height and no radius; the place does (a card's `.card__placeholder` passes its own radius on).
- Used on: the listing, Merkliste and home cards (`search/_card`), the similar-venues cards, the room cards of the detail page, the home hero without a
  spotlight photo, and, where the detail page has no hero, a short band (`.venue-placeholder`) in the hero's place.
- A new icon needs a `<symbol>` in `assets/icons/sprite.svg` (sorted, no presentation attributes) and a line in `assets/icons/LICENSES.md`.

## Share card and icons

Every page without a photo of its own shares `public/brand/og-default.png` (`og:image`, `twitter:image`, `summary_large_image`); a venue page with a photo keeps
its photo. The Organization JSON-LD carries `logo` = `/brand/icon-512.png`. `public/brand/` holds plain files, not AssetMapper assets, because browsers and
crawlers ask for some of them by a fixed name; the head's CSP therefore allows `manifest-src 'self'`.
