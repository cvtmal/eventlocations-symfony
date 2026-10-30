---
paths:
  - "templates/**"
  - "assets/**"
  - "translations/**"
---

# Frontend conventions (from #1 design-system)

Reference: `/_styleguide` (dev and test only) renders every component; compare your page with it.

- **Tokens:** use only `var(--…)` from `assets/styles/abstracts/_tokens.scss` (colours `--c-*`, type `--fs-*`/`--lh-*`, `--space-*`, `--r-*`, `--sh-*`). No hex values in partials. Breakpoints: `@use '../abstracts/breakpoints' as bp;` then `@include bp.up(md) { … }` (sm 568, md 768, lg 1024, xl 1440).
- **Your partial:** one per component at `assets/styles/<module>/_<name>.scss`, with one `@use '<module>/<name>';` line appended to the end of `assets/styles/app.scss` (hotspot: append only). `@use`, never `@import`; no `!important`. `app.css` stays ≤ 25 KB gzip (`AssetBudgetTest`).
- **Components** (`templates/components/`): include atoms with `{% include 'components/_x.html.twig' with {…} only %}`, embed shells with `{% embed 'components/_x.html.twig' with {…} only %}`. Pass already-translated strings.
  - `_button`: `label`, `variant` (primary|black|outline|round|round-lg|icon|close), `href`, `type`, `icon`, `label_hidden`, `attributes`.
  - `_chip`: `label`, `href`, `remove_label`, `variant` (active|neutral). `_badge`: `label`. `_toast`: `message`, `link_href`, `link_label`.
  - `_text_input`: `id`, `name`, `label`, `type`, `value`, `placeholder`, `autocomplete`, `label_hidden`.
  - `_overlay` (shell): `id`, `tone` (region|guests|more), `title`, `close_label`; blocks `overlay_body`, `overlay_footer`.
  - `_dialog` (shell): `id`, `title`, `close_label`; blocks `dialog_body`, `dialog_footer`.
  - `_carousel` (shell): `id`, `label`, `previous_label`, `next_label`, `counter_format`; block `slides` (`<li class="carousel__slide">`).
  - Search partials (#7, include with `only`): `search/_card` (`card`, `index`, `action` merken|none), `search/_card_facts` (`capacity_min`, `capacity_max`, `town_name`), `search/_card_rating` (`score`, `count`, `highlights`), `search/_related_searches` (`links` list<{label, href}>, `title`).
- **Images:** venue photos only through `{{ venue_picture(picture, sizes=…, loading=…, priority=…, widths=…, class=…) }}` (Media); cards pass `widths=[360, 640, 960]`; one LCP image per page gets `priority=true`; never a raw `<img>` for a venue photo and never a `style` background (the placeholder is an SVG `fill`).
- **Icons:** `{{ icon('name', {label: '…', class: 'icon--sm'}) }}`; without `label` the icon is decorative. A new icon is a `<symbol id viewBox="0 0 24 24">` added to `assets/icons/sprite.svg` (sorted by id, no `stroke`/`fill` attributes) plus a line in `assets/icons/LICENSES.md`. `IconSpriteTest` fails on an unknown name.
- **JS:** one self-contained module per behaviour in `assets/js/` (no `import`/`export`, no globals, no `console`), loaded with `<script type="module" src="{{ asset('js/x.js') }}"></script>` in `{% block scripts %}`. No `importmap()`, no inline `<script>`, no `style` attribute or `<style>` (CSP, #16). User-visible strings reach JS through `data-*` attributes. ≤ 15 KB gzip JS per page.
- **Overlays:** `popover` + `popovertarget` (works without JS) plus `js/overlay.js` (focus in, Tab wraps). **Dialogs:** `<dialog>` opened by `commandfor`/`command="show-modal"` plus `js/dialog.js` (fallback, backdrop close). **Carousels:** scroll-snap track plus `js/carousel.js`.
- **Contrast:** text on pink is `--c-ink-inverse`. Inactive states use weight or underline, never opacity. A new colour pair gets a row in `tests/Unit/Shared/ContrastTokensTest.php`.
- **Slots** (fill them, do not restructure `templates/base.html.twig` or `templates/layout/*`):
  - `base.html.twig` blocks: `title`, `meta`, `canonical`, `hreflang`, `stylesheets`, `jsonld`, `body_class`, `hero`, `main`, `scripts`;
  - header link targets in `layout/_header.html.twig`: the search link is `path('listing')` (#7); #13 changes the heart `path('home')`;
  - listing filter bar: `templates/search/filter/_{region,guests,more}.html.twig` (#10, #11, #12; variables `criteria` + `part` `chips|trigger`, render `<li class="filter-bar__item">`s), never `search/_filter_bar.html.twig` itself; `search/filter/_hidden_state.html.twig` (`criteria`, `except`) for their GET forms;
  - `favorites/_merken_button.html.twig` (`number`, `name`, `label`, `variant` card|detail; #13 owns it, wires route and JS);
  - `layout/_main_menu.html.twig`: append one `<li>` per menu item;
  - footer includes `seo/_footer_cantons.html.twig` (#17) and `site/legal/_footer_links.html.twig` (#18), both `ignore_missing`;
  - the toast live region `[data-toast-region]` in `base.html.twig` (#13);
  - the styleguide map slot `styleguide/_canton_map.html.twig` (#3).
- **Checks for every UI cycle:** `make lighthouse URL=/path` (reports in `var/lighthouse/`), `make validate-html URL=/path`, the screenshot comparison at 390 and 1440 px, and a keyboard-only walkthrough.
