---
paths:
  - "src/Geo/**"
  - "data/geo/**"
  - "assets/maps/**"
  - "translations/geo*"
  - "tests/{Unit,Integration,Application}/Geo/**"
---
Append current-state lines for your cycle at the end (last commit).

### Geo

Cantons, towns, place lookup, distances, canton map. Read it by hand before writing SQL on place names or editing `data/geo/towns.csv`.

## Data

- **Tables:** `cantons` (26 cantons plus `li`; PK is the lower-case `code`, a documented exception to `EntityId`), `towns` (one row per `(canton_code, name)`, lowest-ZIP data), `town_zips` (every ZIP); FK targets for other modules: `towns.id`, `cantons.code`.
- **Folding** (baseline migration `Version20261106174839`): SQL on place names must fold through `geo_fold`, `geo_fold_de` (ü to ue) or `geo_search_key` (both) to hit the GIN trigram index `idx_towns_search_key`; `idx_towns_earth` (GiST, `ll_to_earth(lat, lng)`) serves radius queries. Both are expression indexes, invisible to DBAL and deliberately unmapped.
- **Import:** `app:geo:import-towns [path]` loads `data/geo/towns.csv` (`data/geo/SOURCE.md`; `TownDatasetTest::test_real_dataset` pins 4,070 rows and 3,972 towns): idempotent, never deletes towns (only stale ZIP rows), reports stale towns. `make seed` and each deploy run it after the migration, which seeds the cantons only (the 13 Liechtenstein towns come from the CSV).

## Lookup, distances, cantons, map

- **`PlaceLookup::suggest()`:** at most 8. Digits: ZIP prefix, exact match first. Text: cantons first (BFS order, so `li` last), then towns by name prefix, word prefix, trigram, each by population. A canton suggestion carries only its code (`PlaceSuggestion` `lat`/`lng` are null); town entries of `/_search/places` end in `"lat", "lng"`, which `region-filter.js` reads for the pin.
- **`PlaceResolver::resolve()`:** town UUID or 4-digit ZIP (its most populous town, as in `ListingSql`); memoized per value, misses too (`kernel.reset`).
- **`TownLocator`:** `withinKm()` takes 0 < km <= 200 (nearest first, centre town included); `nearest()` finds a town within 20 km, else null.
- **`Canton`:** BFS order, then `LI` last; labels `canton.<code>` (`geo` domain); `countryCode()` is `LI` or `CH`. Whatever means the 26 Swiss cantons must leave `LI` out (`CantonMapExtensionTest`, `CantonMapSvgTest`, DemoStory's `% 26`).
- **Map:** `canton_map()` inlines `assets/maps/cantons.svg` (`<g id="cantons">` with 26 `ch-<code>` paths, `<g id="lakes">`; licence in `assets/maps/LICENSES.md`); `li` has no shape, so `region-filter.js` presses no path; the pin is an affine fit (2.29 SVG units per km).
