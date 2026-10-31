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

- Tables: `cantons` (26 rows seeded by the migration; primary key `code`, the lower-case canton code — a documented exception to `EntityId`, since it is a code-keyed vocabulary row; `bfs_number`, `slug`, `svg_id`, centroid), `towns` (UUID `id`; one row per `(canton_code, name)`, lowest ZIP, `slug`, `bfs_number`, `lat`/`lng`, municipal `population`) and `town_zips` (every ZIP of a town). Entities in `Geo\Entity`; FK targets for later modules are `towns.id` and `cantons.code`.
- SQL functions `geo_fold(text)`, `geo_fold_de(text)` (ü → ue) and `geo_search_key(text)` (both folds). New SQL that compares place names must fold through them so it matches the GIN trigram index `idx_towns_search_key` on `geo_search_key(name)`. The GiST index `idx_towns_earth` on `ll_to_earth(lat, lng)` serves radius queries. Both are expression indexes, invisible to DBAL and deliberately unmapped.
- `app:geo:import-towns [path]`: idempotent import of `data/geo/towns.csv` (console → `ImportTowns` → handler → `TownRepository` upserts). Never deletes towns, only stale ZIP rows; reports stale towns. Run by `make seed` and once after each deploy's migrations.
- Read models: `PlaceLookup::suggest(string $q): list<PlaceSuggestion>` (cantons, then towns; ZIP prefix, name prefix, word prefix, trigram; by population; ≤ 8) and `TownLocator::withinKm(string $townId, float $km): list<TownDistance>` (0 < km ≤ 200, nearest first, centre town included).
- Labels: the `Canton` enum (`Geo\ValueObject`, implements `TranslatableInterface`) and the `geo` domain (`translations/geo+intl-icu.de.yaml`, keys `canton.<code>`). Canton suggestions carry only the code.
- Map: `{{ canton_map() }}` inlines `assets/maps/cantons.svg` (`<g id="cantons">` with `<path id="ch-<code>">`, `<g id="lakes">`); source, licence and build recipe in `assets/maps/LICENSES.md`. Dataset source note: `data/geo/SOURCE.md`.

Built in #10 (`filter-region`):

- `Geo\ReadModel\PlaceResolver::resolve(string $place): ?ResolvedPlace` (town UUID or 4-digit ZIP; a ZIP means its most populous town, the rule of `ListingSql`; memoized per value, misses too, `kernel.reset`). `ResolvedPlace`: `name`, `zip`, `lat`, `lng`, `label()` ("8712 Stäfa").
- `PlaceSuggestion` has `lat`/`lng` (null for a canton), so town entries of `/_search/places` now end in `"lat", "lng"`. A map pin uses the affine fit in `assets/js/region-filter.js` (2.29 SVG units per km, 96.4 % of towns land in their own canton).
