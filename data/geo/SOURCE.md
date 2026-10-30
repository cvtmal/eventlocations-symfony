# `towns.csv`: source note

Swiss localities (Ortschaften) with postcode, canton, municipality, coordinates and population. Imported by `app:geo:import-towns` (run by `make seed`).

## Sources

| Source | Gives | URL | Licence |
|---|---|---|---|
| swisstopo, **Amtliches Ortschaftenverzeichnis** (official directory of towns and cities with postcodes), state September 2026 | ZIP, Ortschaft name, canton, BFS municipality number, LV95 coordinates | https://data.geo.admin.ch/ch.swisstopo-vd.ortschaftenverzeichnis_plz/ortschaftenverzeichnis_plz/ortschaftenverzeichnis_plz_2056.csv.zip | swisstopo free geodata: free use incl. commercial, source must be given (https://www.swisstopo.admin.ch/en/terms-of-use-free-geodata-and-geoservices) |
| Bundesamt für Statistik (BFS), **Demografische Bilanz nach institutionellen Gliederungen** (PXWeb `px-x-0102020000_201`) | Permanent resident population per municipality, year-end 2024 | https://www.pxweb.bfs.admin.ch/api/v1/de/px-x-0102020000_201/px-x-0102020000_201.px | **OPEN-BY** "Freie Nutzung – Quellenangabe ist Pflicht" (verified 2026-10-30 via https://dam-api.bfs.admin.ch/hub/api/dam/assets?orderNr=px-x-0102020000_201, `termsOfUse`) |

Attribution: **Quelle: swisstopo, Amtliches Ortschaftenverzeichnis; Bundesamt für Statistik (BFS)**

The file was built for the earlier Laravel attempt (`hochzeitslocation/database/data/towns.csv`, documented in its `docs/eventlokale-import.md`) and copied here byte for byte. The generator script was never checked in; a refresh re-derives the file from the two URLs under the rules below.

## Transform rules (raw directory: 5,718 rows)

1. **LV95 → WGS84** with swisstopo's official approximation formulas (~1 m). All rows land inside the Swiss bounding box.
2. **One row per `(zip, name)`.** An Ortschaft can straddle municipalities; the row with the largest `Adressenanteil` (address share) wins and supplies the BFS number and the coordinates.
3. **Liechtenstein dropped** (20 rows without a Swiss canton).
4. **PO-box-only districts dropped** (3 rows): the name ends in a number equal to the `Zusatzziffer` (`Lausanne 25/26/27`), while genuine names such as `Laax GR 2` stay.
5. **Municipality successors.** BFS numbers newer than the 2024 population table get their predecessors' populations summed: `2056 Fétigny-Ménières` (merger) and `6831 Moutier` (moved from BE to JU on 2026-01-01).

Population is per **municipality**: every Ortschaft of one BFS number carries the same value.

## Format

- Header `zip,name,canton_code,bfs_number,lat,lng,population`; **4,057** data rows.
- Comma-separated, no quoting; UTF-8 without BOM; **CRLF** line endings (kept as is; `.gitattributes` marks the file `-text`).
- `canton_code` is one of the 26 upper-case codes; `lat`/`lng` WGS84 with 6 decimals.
- 196,844 bytes, SHA-256 `a565e758407eb85a7ebb51ec2c537a65069f71e696abfd0cb0596bb04be8f5f8`.

## Collapse rule (applied by the import)

One town per `(canton, name)`: the row with the lowest ZIP (in Switzerland the central one) supplies coordinates, BFS number and population; every ZIP of the group stays searchable in `town_zips`. 4,057 rows → **3,959 towns**, 4,057 ZIP entries (Zürich: one town, 24 ZIPs). The import never deletes towns; it reports towns no longer in the file.
