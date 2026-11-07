# Canton map (`cantons.svg`)

`cantons.svg` draws the 26 Swiss cantons (`<g id="cantons">`, one `<path id="ch-<code>">` per canton, multipolygons kept as one path) with the 22 large lakes on top (`<g id="lakes">`). It is inlined by the Twig function `canton_map()` (`src/Geo/Twig/CantonMapExtension.php`).

## Source

| | |
|---|---|
| Dataset | Bundesamt für Statistik (BFS), GEOSTAT: *Generalisierte Gemeindegrenzen* / "Historized boundaries K4", state **1 January 2026** (published 2026-02-02) |
| Layers used | `Cantons_K4_20260101` (full canton area, LV95 / EPSG:2056) and `Lacs_K4_20260101` (22 lakes) from `historisierte-administrative_grenzen_k4_2026-01-01_2056.shp.zip` |
| Download | https://data.geo.admin.ch/ch.bfs.historisierte-administrative_grenzen_k4/historisierte-administrative_grenzen_k4_2026-01-01/historisierte-administrative_grenzen_k4_2026-01-01_2056.shp.zip (STAC item: https://data.geo.admin.ch/api/stac/v1/collections/ch.bfs.historisierte-administrative_grenzen_k4/items/historisierte-administrative_grenzen_k4_2026-01-01) |
| BFS catalogue entry | https://dam-api.bfs.admin.ch/hub/api/dam/assets/36389714 ("Generalisierte Gemeindegrenzen: Geodaten", package `2026-0317` "Generalisierte Gemeindegrenzen 01.01.2026: Geodaten") |
| Licence | **OPEN-BY** — "Freie Nutzung – Quellenangabe ist Pflicht" / "Open use – Must provide the source" (`termsOfUse` of DAM assets 36389714 and 36390123, read 2026-10-30). See https://dam-api.bfs.admin.ch/hub/api/dam/assets/36389714 (`description.categorization.termsOfUse[0].code = OPEN-BY`) |
| Attribution | **Quelle: Bundesamt für Statistik (BFS), GEOSTAT** |

Note: the STAC collection `ch.bfs.historisierte-administrative_grenzen_k4` on data.geo.admin.ch links the generic opendata.swiss terms page with the anchor `#terms_by_ask` (read 2026-10-30). The licensor's own catalogue (BFS DAM, above) labels the 2026 geodata `OPEN-BY`; we rely on that label. Re-check before a commercial launch if in doubt.

The `fill`, `stroke` and related presentation attributes on the two groups are ours (neutral defaults so the file is legible before #10 styles it); the geometry is unchanged apart from simplification.

## Moutier

Moutier (BFS 6831) moved from canton Bern to canton Jura on 2026-01-01. The 1.1.2026 edition already draws it in Jura. Check: a point at Moutier's coordinates from `data/geo/towns.csv` (`2740,Moutier,JU,6831,47.277359,7.369120`) joined onto the 40 %-simplified canton layer returns `KTKZ = JU` (run 2026-10-30, output `2740,Moutier,JU,6831,47.277359,7.36912,7256,JU`).

## Reproducible build

One-off tools in a throwaway container; nothing is added to the repository: mapshaper 0.7.101 (MPL-2.0) and SVGO 4.1.0 (MIT). Work directory `var/geo-build/` (git-ignored), the zip unpacked into `var/geo-build/src/shp/` (`unzip -j … 'Historized_boundaries_K4_20260101_2056.shp/Cantons_K4_20260101.*' 'Historized_boundaries_K4_20260101_2056.shp/Lacs_K4_20260101.*'`).

```sh
cd var/geo-build
run() { docker run --rm -v "$PWD:/work" -w /work -e npm_config_cache=/work/.npm node:22-alpine "$@"; }

# 1. Geometry → SVG (LV95 as is, 800 px wide viewBox)
run npx --yes mapshaper@0.7.101 \
  -i src/shp/Cantons_K4_20260101.shp src/shp/Lacs_K4_20260101.shp combine-files \
  -rename-layers cantons,lakes \
  -simplify 40% keep-shapes 'target=*' \
  -each 'id="ch-"+KTKZ.toLowerCase()' target=cantons \
  -o out/raw-40.svg format=svg id-field=id precision=0.1 width=800 'target=*'

# 2. SVGO, keeping ids and both groups
cat > svgo.config.mjs <<'JS'
export default {
  multipass: true,
  floatPrecision: 1,
  plugins: [
    { name: 'preset-default', params: { overrides: { cleanupIds: false, collapseGroups: false, mergePaths: false } } },
    'removeDimensions',
  ],
};
JS
run npx --yes svgo@4.1.0 --config svgo.config.mjs -i out/raw-40.svg -o out/svgo-40.svg

# 3. Post-process (Python standard library, ElementTree): the root keeps only xmlns and
#    viewBox; every attribute except id/d is removed from the paths; the groups get
#      cantons: fill="#ffffff" stroke="#ffffff" stroke-width="0.8" stroke-linejoin="round" fill-rule="evenodd"
#      lakes:   fill="#6b8aa0" stroke="none"
#    and the file is written without an XML prolog → assets/maps/cantons.svg
```

Result: 26,690 bytes (budget 40 KB), `viewBox="0 0 800 533"`.

Moutier check:

```sh
run npx --yes mapshaper@0.7.101 \
  -i src/shp/Cantons_K4_20260101.shp name=cantons \
  -simplify 40% keep-shapes \
  -i out/moutier.csv name=moutier string-fields=zip \
  -points x=lng y=lat \
  -proj match=src/shp/Cantons_K4_20260101.prj init=wgs84 target=moutier \
  -join cantons fields=KTKZ target=moutier \
  -o out/moutier-join.csv target=moutier
```

(`out/moutier.csv` = the header and the Moutier row of `data/geo/towns.csv`.)

## Canton centroids

The `cantons.centroid_lat` / `centroid_lng` values in the migration come from the same, unsimplified canton layer (inner point, so it lies inside the canton; WGS84, 4 decimals):

```sh
run npx --yes mapshaper@0.7.101 \
  -i src/shp/Cantons_K4_20260101.shp \
  -proj wgs84 \
  -points inner \
  -each 'lat=Math.round(this.y*10000)/10000, lng=Math.round(this.x*10000)/10000' \
  -filter-fields KTNR,KTKZ,lat,lng \
  -sort KTNR \
  -o centroids.csv
```

Sanity check: every centroid lies within 15 km of the approximate centroids of the earlier Laravel attempt (largest: BE 14.3 km, SO 13.4 km, FR 11.7 km) and inside the Swiss bounding box.
