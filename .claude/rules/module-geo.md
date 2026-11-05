---
paths:
  - "src/Geo/**"
  - "data/geo/**"
  - "assets/maps/**"
  - "translations/geo*"
  - "tests/{Unit,Integration,Application}/Geo/**"
---

### Geo

Cantons, towns, postcode/town lookup, distances.

Built in #3:

- Tables: `cantons` (27 rows seeded by migrations: the 26 cantons and `li` (Liechtenstein, pseudo BFS number 27, #46); primary key `code`, the lower-case canton code — a documented exception to `EntityId`, since it is a code-keyed vocabulary row; `bfs_number`, `slug`, `svg_id`, centroid), `towns` (UUID `id`; one row per `(canton_code, name)`, lowest ZIP, `slug`, `bfs_number`, `lat`/`lng`, municipal `population`) and `town_zips` (every ZIP of a town). Entities in `Geo\Entity`; FK targets for later modules are `towns.id` and `cantons.code`.
- SQL functions `geo_fold(text)`, `geo_fold_de(text)` (ü → ue) and `geo_search_key(text)` (both folds). New SQL that compares place names must fold through them so it matches the GIN trigram index `idx_towns_search_key` on `geo_search_key(name)`. The GiST index `idx_towns_earth` on `ll_to_earth(lat, lng)` serves radius queries. Both are expression indexes, invisible to DBAL and deliberately unmapped.
- `app:geo:import-towns [path]`: idempotent import of `data/geo/towns.csv` (console → `ImportTowns` → handler → `TownRepository` upserts). Never deletes towns, only stale ZIP rows; reports stale towns. Run by `make seed` and once after each deploy's migrations.
- Read models: `PlaceLookup::suggest(string $q): list<PlaceSuggestion>` (cantons, then towns; ZIP prefix, name prefix, word prefix, trigram; by population; ≤ 8) and `TownLocator::withinKm(string $townId, float $km): list<TownDistance>` (0 < km ≤ 200, nearest first, centre town included).
- Labels: the `Canton` enum (`Geo\ValueObject`, implements `TranslatableInterface`) and the `geo` domain (`translations/geo+intl-icu.de.yaml`, keys `canton.<code>`). Canton suggestions carry only the code.
- Map: `{{ canton_map() }}` inlines `assets/maps/cantons.svg` (`<g id="cantons">` with 26 `<path id="ch-<code>">`, `<g id="lakes">`; Liechtenstein has no shape (#46, owner answer B), and `region-filter.js` presses no path for `li`); source, licence and build recipe in `assets/maps/LICENSES.md`. Dataset source note: `data/geo/SOURCE.md`.

Built in #10 (`filter-region`):

- `Geo\ReadModel\PlaceResolver::resolve(string $place): ?ResolvedPlace` (town UUID or 4-digit ZIP; a ZIP means its most populous town, the rule of `ListingSql`; memoized per value, misses too, `kernel.reset`). `ResolvedPlace`: `name`, `zip`, `lat`, `lng`, `label()` ("8712 Stäfa").
- `PlaceSuggestion` has `lat`/`lng` (null for a canton), so town entries of `/_search/places` now end in `"lat", "lng"`. A map pin uses the affine fit in `assets/js/region-filter.js` (2.29 SVG units per km, 96.4 % of towns land in their own canton).

**Built in #46 `liechtenstein-venues`.** One migration, `Version20261104100457` (CHECK `chk_cantons_bfs_number` 1–27, the `li` row with the 13 towns' mean as centroid); no package.

- **`Canton::LI`** (`'li'`, label `canton.li` "Liechtenstein") is the last case, after `JU`: the enum stays "BFS order, then Liechtenstein". `Canton::countryCode()` gives the ISO 3166 code, `LI` for `li` and `CH` for the 26 cantons; JSON-LD and the geocoder read it. Anything that means "the 26 Swiss cantons" must leave `LI` out (`CantonMapExtensionTest`, `CantonMapSvgTest`, DemoStory's `% 26` rotation).
- **Towns:** the 13 Liechtenstein rows (ZIP 9485–9498, canton `LI`, BFS number = Gemeinde number 7001–7011, population 0, "Mauren FL" → "Mauren") sit in `data/geo/towns.csv` in zip order, derived by `data/geo/SOURCE.md`'s rules from the same LV95 file as the Swiss rows: 4,070 rows, 3,972 towns, 4,070 ZIPs. The deploy's `app:geo:import-towns` loads them; the migration does not.
- **Place search:** "vaduz" and "9490" find Vaduz, and "liech" suggests the `li` region; among region suggestions `li` comes last, as `PlaceLookup` orders by BFS number (`PlaceLookupTest`).
- **Rollback:** `down()` deletes the Liechtenstein towns and the `li` row, so it fails on the FK while a venue is in Liechtenstein and never deletes one. After a rollback the code must roll back too, or the next `app:geo:import-towns` fails on the missing `li` row.

**Built in #52 (`near-me-map`).** `TownLocator::nearest(float $lat, float $lng): ?TownDistance` gives the nearest town within 20 km of a point (`earth_box` over `idx_towns_earth`, then distance and name), `null` beyond; near-me uses it for its 50 km link after a position search. Tests in `TownLocatorTest`.
