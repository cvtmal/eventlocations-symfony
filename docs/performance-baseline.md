# Performance baseline

_Last updated: 2026-11-09 · Source: `docs/rpi-cycles.md` S11 (PERF-1 budgets), §F16 (dry-run finding S1 and N10) · Tree: `origin/main` at `51ced0c` (#73 `f16-listing-lcp`); detail pages and k6 run 3 on `c8b03ef` (`f16-google-off-cleanup`)_

Every figure here is from deine-eventlocation.ch, measured on 2026-11-09. The figures from before the fork belong to the sister site and are gone. The method and the measurement tools came over with the fork, and §9 keeps the caveats that are properties of the shared code.

## 1. Summary

- **Lighthouse and the byte budgets hold on every public page type.** Thirteen pages, three Lighthouse runs each, on demo ×1. The worst LCP is the listing at 1,957 ms (runs 1,954 to 1,960) against 2,000. CLS is at most 0.002 and TBT at most 79 ms. Performance, Accessibility and Best Practices are 99 to 100 everywhere. SEO is 100 on every indexable page. The landing pages (66, on demo data only), `/merkliste` and `/inhalt-melden` (63) are `noindex`, and `is-crawlable` is their only failing audit. **`/sammelanfrage` scores SEO 54:** it is `noindex` too, but it also fails `meta-description`, which is not by design (§8).
- **BLOCKED: the detail page's uncached p95 misses its budget in 2 of 3 k6 runs.** Three `make perf-run MODE=all` runs on demo ×10, all above the load-4 rule (§10):
  - Run 2, at load average 11.9 to 7.8, exits 0. Uncached p95 is 54.1 to 116.3 ms on `/` and the listing pages against 200, and 92.0 ms on the detail page against 120. Cached p95 is 6.2 to 11.6 ms against 20.
  - Run 1, at load 10.5 to 17.7, exits 2. Detail is 128.3 ms against 120, cached listing 20.2 ms and cached canton 21.2 ms against 20.
  - Run 3, on `c8b03ef` at load 22.7 to 14.0, exits 2. Detail is 196.6 ms against 120 and cached canton 48.8 ms against 20. `/` and the listing pages stay under 200 ms in every run (at most 164.1 ms).
  - No run had a `server_error` or a short body. The detail p95 follows the host's load: 92.0, 128.3 and 196.6 ms at rising load. The perf containers peaked at about half a core in 5-second samples, which points at host contention, but samples that coarse cannot prove it (§5). The load did not fall below 4 while seven lane stacks ran. A run on an idle host decides it. If the detail page still misses there, the lever is `SimilarVenuesQuery` (§6).
- **Byte budgets:** `make perf-check` exits 0 (26 tests). CSS is 15,451 B gzip, 60 % of its budget. N10's 92 % counted the dev build's inline source map, which the test strips (§7). The listing HTML is the tight one: 56,631 B served at nginx's level 5 (92 %). At level 9, which is how the test counts, it is 55,065 B (90 %), and the test fixtures are 54,901 to 55,295 B (89 to 90 %).
- **Watch items** (worst run at 90 % of the budget or more, §8): listing LCP (98 %), landing LCP (94 % in one run), listing HTML (92 % served, about 90 % in the fixtures), detail uncached p95 (164 % in k6 run 3, the BLOCKED item), cached p95 (244 % in run 3), and the region overlay's script budget (99.8 %, a test budget beside S11). On the listing, LCP and HTML move together: its LCP image is 3 KB, so its simulated LCP tracks the 57 KB document.
- **Open:** the k6 re-run on an idle host (BLOCKED until then), and `/sammelanfrage`'s meta description (§8).

| Budget (S11) | Limit | Measured | Share | Pass | Watch |
|---|---|---|---|---|---|
| LCP, worst page (`/eventlokale`) | ≤ 2.0 s | 1,957 ms (1,954–1,960) | 98 % | yes | **yes** |
| LCP, landing pages (event type, canton) | ≤ 2.0 s | 1,804 ms (1,727–1,878), 1,802 ms (1,729–1,803) | 94 %, 90 % | yes | **yes** |
| LCP, detail pages (richest, Pro with offer), on `c8b03ef` | ≤ 2.0 s | 1,727 ms (1,727–1,731), 1,728 ms (1,728–1,732) | 87 % | yes | no |
| LCP, every other page | ≤ 2.0 s | 1,354–1,507 ms, single runs up to 1,532 ms | ≤ 77 % | yes | no |
| CLS, worst page | ≤ 0.05 | 0.002 (detail) | 4 % | yes | no |
| TBT, worst single run | ≤ 100 ms | 79 ms (`/location-eintragen`, run 1; median 0) | 79 % | yes | no |
| Lighthouse P / A / BP | ≥ 95 each | 99–100 | – | yes | no |
| Lighthouse SEO, indexable pages | ≥ 95 | 100 | – | yes | no |
| Lighthouse SEO, `noindex` pages | ≥ 95 | 63–66, `is-crawlable` only | – | `noindex` (landing pages: on demo data) | no |
| Lighthouse SEO, `/sammelanfrage` | ≥ 95 | 54: `is-crawlable` and `meta-description` | – | **no**, follow-up (§8) | – |
| Uncached p95, `/` and listing pages, demo ×10 | ≤ 200 ms | run 2: 54.1–116.3 ms; run 1: 83.0–154.5 ms; run 3: 81.8–164.1 ms | 58 %; 77 %; 82 % | yes in all three runs | no |
| Uncached p95, detail, demo ×10 | ≤ 120 ms | run 2: 92.0 ms; run 1: **128.3 ms**; run 3: **196.6 ms** | 77 %; 107 %; 164 % | **BLOCKED**: 1 of 3 runs, all at load ≥ 7.8 | **yes** |
| Cached p95, all five pages, demo ×10 | ≤ 20 ms (#19) | run 2: 6.2–11.6 ms; run 1: 9.1–**21.2** ms; run 3: 11.3–**48.8** ms | 58 %; 106 %; 244 % | **open**: 1 of 3 runs | **yes** |
| CSS gzip | ≤ 25 KB | 15,451 B | 60 % | yes | no |
| JS gzip per page, worst (listing) | ≤ 15 KB | 13,149 B | 86 % | yes | no |
| Listing HTML gzip, served (48 cards) | ≤ 60 KB | 56,631 B at level 5; 55,065 B at level 9 | 92 %; 90 % | yes | **yes** |
| Listing HTML gzip, test fixtures (level 9) | ≤ 60 KB | 54,901–55,295 B | 89 to ≈ 90 % | yes | **yes** |
| Font files on first view | ≤ 2 | 1 | 50 % | yes | no |

"Share" is the measured value as a share of its limit, from the worst run. For k6, every run is listed, the passing run 2 first.

## 2. Setup

- **Stack.** `compose.perf.yaml`, Compose project `deine-eventlocation-perf` on :8096 and :5446, beside the dev lane. It is one shared project, so only one lane runs it at a time.
  - `APP_ENV=prod`, `APP_DEBUG=0`. Composer runs `--no-dev --classmap-authoritative`. `cache:clear`, `sass:build` and `asset-map:compile` run in the container.
  - Xdebug is off. OPcache has `validate_timestamps=0`, so a code change needs `make perf-up`.
  - php-fpm uses a static pool of 10 children.
  - Postgres 18 runs with `pg_stat_statements` and `shared_buffers=512MB`.
  - `vendor/`, `var/` and `public/assets/` are named volumes. `public/media` is bind-mounted, so the photos are real files.
  - nginx compresses at `gzip_comp_level 5`, as `deploy/nginx/deine-eventlocation.ch.conf` does in production.
- **Size.** nginx, php and Postgres share two cores (`cpuset: "0,1"`), like a 2 vCPU host. Their memory limits are 256 MB, 1.5 GB and 2 GB.
- **Host.** Apple M4 Pro; the Docker VM has 14 CPUs and 11.4 GiB. Seven other lane stacks ran on it (`docker ps`): the load average was 30 to 11 during Lighthouse, 10.5 to 17.7 during k6 run 1, 11.9 to 7.8 during run 2 and 22.7 to 14.0 during run 3. Lighthouse throttling is simulated, so its LCP figures stand. TBT and k6 carry the noise (§9).
- **Tree.** `origin/main` at `51ced0c` (`f16-listing-lcp`, #73), which includes F13 (`multi-inquiry`, `product-doc-f13`). Run from lane L4 on branch `f16-perf-baseline`, which changes only docs. Two commits landed during the run. `f16-ops-guards` touches no measured page. `f16-google-off-cleanup` changes the detail page, which now loads `google-rating.js` and `google-photos.js` only while their feature is on. So the detail pages' Lighthouse rows, their served HTML and k6 run 3 were re-measured on `c8b03ef`, the tree with both. On `51ced0c` the detail pages measured 1,727 ms (1,726–1,878) and 1,819 ms (1,803–1,884).
- **Tools.** Lighthouse 13.5.0 (`docker/lighthouse`), mobile, simulated slow 4G. k6 2.3.0 (`grafana/k6:2.3.0`).

## 3. Datasets

| Used for | Data | Published venues | Photos |
|---|---|---|---|
| Lighthouse | Demo ×1 (`make db-fresh && make seed`), real image files | 60 | 390 |
| k6 | Demo ×10, cloned by `tests/Load/times-ten.sql` | 600 | the venue-level photos ×10, without files; rooms are not cloned |

Both come from a `pg_dump` of the lane. Prod mode lists a canton page only from 5 venues (`landing.min_venues`), so `tests/Load/live-canton.sql` then moves venues into Zürich in the perf database only, until Zürich has 5. ×10 is cloned from that data, so Zürich has 50 venues there. Cloned photos have no files, so Lighthouse never runs on ×10.

No real venue data exists for this site yet. The F16 run on production data is the owner's (§9).

## 4. Lighthouse

Demo ×1, 2026-11-09 13:22 to 13:32 (Europe/Zurich) on `51ced0c`, and the two detail pages again 14:20 to 14:22 on `c8b03ef` (load 24 to 20), `make perf-lighthouse URL=… NAME=…`. Each page was warmed twice with Lighthouse's `Host`, so every run is an HttpCache hit (or a `no-store` render, for `/merkliste`, `/sammelanfrage` and `/inhalt-melden`). Median of 3 runs, range in brackets. Scores are listed per run when they differ.

| Page | URL | P / A / BP / SEO | LCP | CLS | TBT, max | FCP |
|---|---|---|---|---|---|---|
| Home | `/` | 100 / 100 / 100 / 100 | 1,503 ms (1,502–1,505) | 0.000 | 0 ms | 1,053 ms |
| Listing | `/eventlokale` | 99 / 100 / 100 / 100 | **1,957 ms (1,954–1,960)** | 0.000 | 60 ms | 1,501 ms |
| Landing page, event type | `/eventlokale/firmenanlass` | 99, 99, 100 / 100 / 100 / 66 | 1,804 ms (1,727–1,878) | 0.000 | 28 ms | 1,255 ms |
| Landing page, canton | `/eventlokale/zuerich` | 99, 99, 100 / 100 / 100 / 66 | 1,802 ms (1,729–1,803) | 0.000 | 0 ms | 1,353 ms |
| Near me (no Maps key) | `/eventlokal-in-meiner-naehe` | 100 / 100 / 100 / 100 | 1,503 ms (1,502–1,508) | 0.000 | 0 ms | 1,053 ms |
| Detail, richest venue by `run.sh` (9 photos, 5 rooms), with the inquiry form, `c8b03ef` | `/eventlokal/landgut-talwiese` | 100 / 100 / 100 / 100 | 1,727 ms (1,727–1,731) | 0.002 | 0 ms | 1,116 ms |
| Detail, Pro with a last-minute offer, with the inquiry form, `c8b03ef` | `/eventlokal/landgut-erlenhof` | 100 / 100 / 100 / 100 | 1,728 ms (1,728–1,732) | 0.002 | 0 ms | 1,096 ms |
| Merkliste (empty) | `/merkliste` | 100 / 100 / 100 / 63 | 1,502 ms (1,502–1,504) | 0.000 | 0 ms | 1,052 ms |
| Last minute | `/kurzfristig-frei` | 100 / 100 / 100 / 100 | 1,507 ms (1,504–1,532) | 0.000 | 5 ms | 1,042 ms |
| Tier page | `/location-eintragen` | 100 / 100 / 100 / 100 | 1,507 ms (1,505–1,516) | 0.000 | 79 ms | 1,057 ms |
| Multi-inquiry (F13) | `/sammelanfrage` | 100 / 100 / 100 / 54 | 1,354 ms (1,352–1,502) | 0.000 | 0 ms | 964 ms |
| Legal page | `/impressum` | 100 / 100 / 100 / 100 | 1,504 ms (1,359–1,504) | 0.000 | 0 ms | 1,054 ms |
| Report form | `/inhalt-melden` | 100 / 100 / 100 / 63 | 1,502 ms (1,355–1,504) | 0.000 | 0 ms | 953 ms |

- **The inquiry form has no page of its own.** It sits on every detail page (`POST /eventlokal/{slug}/anfrage`), so the two detail rows measure it. `/inhalt-melden` was added as the other public form.
- **SEO below 95 is `noindex` on every row.** `is-crawlable` is the failing audit in all of them:
  - the two landing pages are `noindex, follow` on demo data by G12's `LandingQualityGate`, which wants 8 entries that pass `VenueIndexGate` (`landing.index_min_venues`); demo entries do not. With checked entries they are indexable.
  - `/merkliste`, `/inhalt-melden` and `/sammelanfrage` are `noindex` by design.
  - `/sammelanfrage` also fails `meta-description`: it renders no `<meta name="description">` (§8).
- **LCP elements.** Listing and landing pages: the first card photo, a 3,050 to 3,187 B 640w AVIF at High, the page's only non-Low image. Detail pages: hero slide 1, a 4,652 B (richest) or 5,372 B (Pro) 960w AVIF at High. The pages without photos end LCP on text.
- **TBT.** Every median is 0 ms except the listing's 57 ms (runs 57, 60, 0). The other non-zero figures are single runs on a loaded host.
- **Card counts on demo ×1.** The listing shows 48 cards, the event-type landing page 9 and the canton page 5.

Transfer of the median run, in bytes, response headers included:

| Page | Document | Images | CSS | JS | Fonts |
|---|---|---|---|---|---|
| Home | 8,634 | 11,434 | 16,520 | 6,503 | 34,998 |
| Listing | 57,565 | 39,820 | 16,520 | 15,701 | 34,998 |
| Landing, event type | 30,401 | 42,750 | 16,520 | 15,701 | 34,998 |
| Landing, canton | 27,667 | 45,684 | 16,520 | 15,701 | 34,998 |
| Near me | 5,494 | 0 | 16,520 | 9,175 | 34,998 |
| Detail, richest (`c8b03ef`) | 12,252 | 28,675 | 16,520 | 13,480 | 34,998 |
| Detail, Pro (`c8b03ef`) | 13,691 | 34,478 | 16,520 | 14,766 | 34,998 |
| Merkliste | 4,843 | 0 | 16,520 | 8,446 | 34,998 |
| Last minute | 8,003 | 15,513 | 16,520 | 6,503 | 34,998 |
| Tier page | 6,431 | 0 | 16,520 | 6,503 | 34,998 |
| Multi-inquiry | 4,389 | 0 | 16,520 | 5,167 | 34,998 |
| Impressum | 4,774 | 0 | 16,520 | 6,503 | 34,998 |
| Report form | 5,548 | 0 | 16,520 | 6,503 | 34,998 |

Transfer is not what the budget counts. JS transfer adds each file's response headers (10 files on the Pro detail page) and nginx's level 5 to the content. The budget counts the files' content at gzip level 9, as `AssetBudgetTest` does: 12,496 B for the detail template with both Google loaders (§7). One font file loads on every page.

## 5. Server time (k6)

k6 2.3.0 over the Compose network, demo ×10, `make perf-run MODE=all`. Uncached: per page a 10 s warm-up, then 60 s at 5 requests/s, each request with a unique `k6=` query, so every request is a backend render plus a store write. Cached: 30 s at 20 requests/s per page after one priming request. Pages: `/`, `/eventlokale`, `/eventlokale?canton=zh&guests=80&event[]=workshop` (three filters), `/eventlokale/zuerich`, and the detail page of `landgut-talwiese` (the richest venue, picked by `run.sh`).

**Run 2, 14:03 to 14:12, load average 11.9 to 7.8: exit 0.** 4,773 requests, 0 failed, 0 `server_errors`, 0 `short_bodies`, checks 4,506 of 4,506, `cache_fresh` 3,003 of 3,003 in the measured phase.

| Page | Uncached p50 | p90 | p95 | max | Budget | Cached p50 | p95 | Budget |
|---|---|---|---|---|---|---|---|---|
| home | 31.3 ms | 45.1 ms | 54.1 ms | 180.0 ms | 200 ms | 3.0 ms | 6.8 ms | 20 ms |
| listing | 58.8 ms | 89.7 ms | 116.3 ms | 361.1 ms | 200 ms | 3.0 ms | 6.2 ms | 20 ms |
| three filters | 46.2 ms | 68.3 ms | 85.8 ms | 288.9 ms | 200 ms | 2.5 ms | 8.2 ms | 20 ms |
| canton page | 53.0 ms | 85.3 ms | 108.0 ms | 275.8 ms | 200 ms | 3.2 ms | 9.6 ms | 20 ms |
| detail | 49.6 ms | 72.3 ms | 92.0 ms | 278.5 ms | 120 ms | 3.0 ms | 11.6 ms | 20 ms |

**Run 1, 13:39 to 13:48, load average 10.5 to 17.7: exit 2.** 4,773 requests, 0 failed, 0 `server_errors`, 0 `short_bodies`. Three thresholds failed:

| Page | Uncached p50 | p95 | Budget | Cached p50 | p95 | Budget |
|---|---|---|---|---|---|---|
| home | 37.7 ms | 83.0 ms | 200 ms | 3.9 ms | 9.1 ms | 20 ms |
| listing | 71.9 ms | 154.5 ms | 200 ms | 5.8 ms | **20.2 ms** | 20 ms |
| three filters | 58.9 ms | 120.4 ms | 200 ms | 4.7 ms | 18.7 ms | 20 ms |
| canton page | 65.6 ms | 118.7 ms | 200 ms | 7.4 ms | **21.2 ms** | 20 ms |
| detail | 57.0 ms | **128.3 ms** | 120 ms | 4.1 ms | 11.2 ms | 20 ms |

**Run 3, on `c8b03ef`, 14:22 to 14:31, load average 22.7 to 14.0: exit 2.** 4,774 requests, 0 failed, 0 `server_errors`, 0 `short_bodies`. Two thresholds failed:

| Page | Uncached p50 | p95 | Budget | Cached p50 | p95 | Budget |
|---|---|---|---|---|---|---|
| home | 35.9 ms | 81.8 ms | 200 ms | 4.5 ms | 19.1 ms | 20 ms |
| listing | 60.3 ms | 136.2 ms | 200 ms | 5.0 ms | 14.7 ms | 20 ms |
| three filters | 52.4 ms | 164.1 ms | 200 ms | 4.0 ms | 11.3 ms | 20 ms |
| canton page | 66.0 ms | 130.5 ms | 200 ms | 4.9 ms | **48.8 ms** | 20 ms |
| detail | 74.7 ms | **196.6 ms** | 120 ms | 3.6 ms | 14.2 ms | 20 ms |

- **The misses are probably the host, not the app, but that is not proven.** In 5-second `docker stats` samples php peaked at 50 % and Postgres at 42 % of one core in run 1 (45 % and 36 % in run 2, 43 % and 56 % in run 3), out of the two cores they share. Samples that coarse cannot show short CPU starvation. From run 2 to run 1 the uncached p50s rose 15 to 28 % and the p95s 10 to 53 %. Cached responses are file reads by HttpCache, so their p95 crossing 20 ms points at scheduling on a VM that seven lane stacks share. Runs 1 and 2 started at similar loads (10.5 and 11.9) with opposite results, so the runs do not settle the budget. In run 3 the detail page was the most load-sensitive page: its p50 went from 49.6 ms in run 2 to 74.7 ms in run 3, and its p95 from 92.0 to 196.6 ms.
- **No run met the rule** of a load average under 4 (§10). The readings between runs 1 and 2, 13:48 to 14:03, were 6.9 to 17.5, and 10 to 35 around run 3. A run on an idle host decides the detail and cached budgets (§8). The detail page has the least headroom under load: 92.0 ms is 77 % of its budget, the 128.3 ms of run 1 is 107 %, and the 196.6 ms of run 3 is 164 %.

## 6. Top SQL

`pg_stat_statements` of run 2 (`var/perf/pgss-all-x10.txt`), by total time:

| Statement | Calls | Mean | Runs on |
|---|---|---|---|
| `SimilarVenuesQuery` (`WITH me AS MATERIALIZED …`) | 351 | 26.7 ms (run 1: 31.7, run 3: 33.7) | every uncached detail render |
| `LandingPages` (canton and town counts) | 1,407 | 5.9 ms | every uncached render of `/` and the listing pages |
| Card photos (`unnest(ARRAY[…]) … venue_photos`) | 703 | 5.0 ms | listing pages |
| `FilterDataQuery` (filter terms with `EXISTS`) | 1,407 | 2.5 ms | `/` and the listing pages |
| Listing cards (`SELECT v.id, v.number, v.name, …`) | 703 | 3.1 ms | listing pages |

`SimilarVenuesQuery` is about half of an uncached detail render's p50, the largest single cost, and the lever if the idle-host re-run still misses. It stays untuned until that run decides.

## 7. Byte budgets

`make perf-check` (`phpunit --group budget`, also in CI) exits 0: 26 tests, 1,156 assertions. The HTML rows are its fixtures, printed with `docker compose exec -T -e SEARCH_PERF_REPORT=1 php vendor/bin/phpunit --group budget`. The CSS and JS rows apply `AssetBudgetTest`'s method by hand, because the test asserts and prints nothing: CSS with the source map comment stripped, each file `gzencode` level 9, and per page the scripts of `base.html.twig` plus the page template's. The served rows are the perf stack's responses on demo ×1, as nginx sends them (level 5).

| Budget | Figure | Limit | Share |
|---|---|---|---|
| Listing HTML, served, `/eventlokale`, 48 cards | 56,631 B gzip (566,172 raw; 55,065 B at level 9) | 61,440 | **92 %** |
| Listing HTML, fixture, 48 cards × 8 photos, full overlay | 54,946 B gzip (758,081 raw) | 61,440 | **89 %** |
| Canton page HTML, fixture | 55,295 B gzip (761,300 raw) | 61,440 | **90 %** |
| Event page HTML, fixture | 54,901 B gzip (758,667 raw) | 61,440 | **89 %** |
| Event page in a canton HTML, fixture | 55,106 B gzip (761,412 raw) | 61,440 | **90 %** |
| Landing pages, served (9 and 5 cards on demo ×1) | event type 29,476 B, canton 26,744 B | 61,440 | 48 %, 44 % |
| Home HTML, fixture | 8,087 B gzip (41,807 raw); served 7,718 B | 61,440 | 13 % |
| Last minute HTML, fixture | 27,574 B gzip (385,230 raw); served 7,088 B | 61,440 | 45 % |
| Near me HTML, fixture A (full list within 30 km) / B (whole map) | 31,067 / 16,688 B gzip | 61,440 | 51 % |
| Detail HTML, fixture (overfull Pro, three offers) | 10,092 B gzip (66,552 raw); served 11,341 / 12,785 B on `c8b03ef` | 61,440 | 16 % |
| `app.css`, as the test measures it | 15,451 B gzip (101,609 raw) | 25,600 | 60 % |
| JS per page | home 5,411 · listing 13,149 · detail 12,496 (both Google loaders counted, as the test reads the template; 10,363 B as shipped with Google off) · Merkliste 6,999 · near me 7,727 B gzip | 15,360 each | 35–86 % |
| `overlay.js + live-count.js + region-filter.js` (test budget) | 6,132 B gzip | 6,144 | **99.8 %** |
| `carousel.js` (test budget) | 1,588 B gzip | 2,048 | 78 % |
| `near-me.js` (test budget) | 2,316 B gzip | 4,096 | 57 % |
| `google-rating.js` / `google-photos.js` (test budgets) | 1,344 / 789 B gzip | 2,048 / 1,024 | 66 % / 77 % |
| Font files on first view | 1 woff2 (2 `@font-face` rules) | 2 | 50 % |
| Admin shared JS, each island, admin CSS | pass (`AdminAssetBudgetTest`) | 60, 30, 30 KB gzip | – |

**N10 re-measured.** The dry run read CSS at 23,522 B (92 %) with `gzip -9` on `var/sass/app-*.output.css`. That file is the dev build and ends in an inline source map, about 29 KB raw; the same command gives 23,817 B on this tree. Without the map, as `AssetBudgetTest` counts, the CSS is 15,451 B, 60 % of the budget. Prod ships no inline map: the perf stack's `app-*.css` is 15,510 B at level 9 with its one-line `sourceMappingURL` comment, and 16,520 B in transfer with headers at nginx's level 5 (§4). The listing HTML was 56,691 B served in the dry run and is 56,631 B now: #73 deferred the card carousels' off-screen images and left the markup as it was.

## 8. Watch items and findings

A figure counts as a watch item when its worst run reaches 90 % of its budget. Lighthouse and the byte figures are all under budget. The two k6 rows are open, because only one of the three runs passed; the detail row is BLOCKED.

| Item | Figure | Why it is tight | What to do before adding to it |
|---|---|---|---|
| Listing LCP | 1,957 ms (98 %) | The LCP image is 3 KB; the simulated LCP follows the 57 KB document over slow 4G (FCP 1,501 ms). #73 took it from 2,028 to under 2,000 ms | Any new card markup, listing copy or above-the-fold script: rerun `make perf-lighthouse URL=/eventlokale` |
| Landing LCP | 1,804 and 1,802 ms medians, single runs to 1,878 ms (90–94 %) | The listing's shape on 9 and 5 cards: a 3 KB LCP photo after a 27–30 KB document | Rerun on a change to the card or the listing template |
| Listing HTML | 56,631 B served (92 %), fixtures 89–90 % | Of the served page at level 9 (55,065 B), the 48 cards alone compress to about 32 KB and the rest to about 23 KB, of which the region and filter overlay forms are about 16 KB (68 KB raw). Parts compressed alone add up to more than the whole | A budget look for any new card or overlay markup (`make perf-check`); the levers are fewer cards per page (`ListingQuery::PER_PAGE` = 48), fewer slides per card, or a lighter overlay |
| Detail uncached p95 (**BLOCKED**) | 92.0 ms (77 %) in run 2, 128.3 ms (107 %) in run 1, 196.6 ms (164 %) in run 3 | `SimilarVenuesQuery` at 26.7–33.7 ms mean (§6) | Re-run k6 on an idle host before F16 signs off. If it still misses, tune `SimilarVenuesQuery`. Any new detail query needs a k6 look |
| Cached p95 (open) | 6.2–11.6 ms in run 2; listing 20.2 ms and canton 21.2 ms (106 %) in run 1; canton 48.8 ms (244 %) in run 3 | HttpCache file reads; the tail follows the host's load | The same idle-host re-run |
| Region overlay JS | 6,132 of 6,144 B (99.8 %) | A test budget beside S11, in `AssetBudgetTest` | Any change to `overlay.js`, `live-count.js` or `region-filter.js` fails `perf-check` once it grows by 12 B |

**Finding, follow-up (SEO below 95, not by design): `/sammelanfrage` has no meta description.** The `meta` block of `templates/inquiries/brief.html.twig` (F13) holds only the robots tag, so the page fails `meta-description` beside `is-crawlable`. It is `noindex, nofollow`, so no search result shows it, but every other public page carries a description. Not fixed here (docs only).

**Fixes in force.** #73 (`f16-listing-lcp`) defers the card carousels: their slides 2+ load on first interaction (`data-carousel-defer`). Before it the listing measured 2,027–2,029 ms on the perf stack. From the fork come `fetchpriority="low"` on the second card's eager photo and on the detail hero's slides 2+ (`MediaExtension`, `lowPriority`), and `alt=""` on the card slides 2+, whose links no reader reaches. Every page with photos has exactly one non-Low image.

## 9. Caveats

- **A loaded host.** Other lanes ran during every measurement (§2). Simulated LCP barely moves with load; TBT spikes in single runs, and k6, which is not simulated, runs slower.
- **The day seed moves the first card.** Listing and landing pages order by a seed that changes at midnight Zurich, so the LCP image holds for its date. On demo data every first-card photo is 3 to 5 KB, so the effect is small here. Real photos can be ten times larger; the sister site's listing crossed 2.0 s with a 44 KB first photo.
- **Demo data only.** No real venue data exists yet. Real descriptions compress worse than demo text and real photos are larger, so the listing's HTML and LCP figures will rise on production data. F16 on the live site is the check.
- **Served and fixture HTML use different gzip levels.** nginx sends level 5, while the budget tests count `gzencode` level 9. The served listing is 56,631 B at level 5 (92 %) and 55,065 B at level 9 (90 %), next to the fixture's 54,946 B (89 %). So the budget as tested has about 10 % headroom, and the bytes on the wire about 8 %.
- **No Google key.** The near-me page and the detail pages ran without a Maps or Places key, as S11 allows: our part must meet the gate. Google's scripts, fonts and element are outside the counts.
- **The lab has only DejaVu.** The Lighthouse image has no Arial or metric twin, so fallback text there is not what a visitor sees before Figtree arrives.
- **Local, not production.** Two local cores and a Postgres in the same Docker VM stand in for the Infomaniak VPS and Neon (eu-central-1). Neon adds a network round trip per query and a cold start after idle.
- **Lighthouse does not show the cache.** Lantern averages the document's server time among all requests, so a cache hit barely moves the simulated LCP. k6's cached phase (§5) is the evidence for the cache.
- **HttpCache's limits, measured before the fork on the shared code** (#19) and not re-measured here: a full `app:http-cache:clear` under load can answer a raw 500 to a request that is reading a body at that moment, about 1–3.5×10⁻⁴ per clear per request/s. A purge cannot. So only the nightly cron and the size guard clear the store. The store never evicts, so `app:http-cache:clear --if-over-limit` on the 5-minute cron bounds it. `var/share/<env>` must be writable by the web user, or HttpCache answers 500 on every URL; `make perf-up` and `make perf-seed` hand `var` to `www-data`. A cached detail page shares one `inquiry[started]` value among its visitors, so the bot rule weakens to "3 s since that page was rendered".

## 10. How to re-run

```sh
make db-fresh && make seed             # the lane, freshly seeded: perf-seed copies its database
make perf-up                           # start and prepare; rerun after every code change
make perf-seed                         # demo ×1, for Lighthouse
make perf-lighthouse URL=/eventlokale NAME=listing
make perf-seed TIMES=10                # demo ×10, for k6
make perf-run MODE=all                 # uncached and cached; MODE=uncached skips the cached half
make perf-down                         # stop and delete the volumes
make perf-check                        # byte budgets; needs no perf stack
docker compose exec -T -e SEARCH_PERF_REPORT=1 php vendor/bin/phpunit --group budget   # prints the HTML fixture sizes
```

The pages of §4 are `/`, `/eventlokale`, `/eventlokale/firmenanlass`, `/eventlokale/zuerich`, `/eventlokal-in-meiner-naehe`, the richest venue by `tests/Load/run.sh` (`landgut-talwiese` on demo), a Pro venue with a last-minute offer (`landgut-erlenhof`), `/merkliste`, `/kurzfristig-frei`, `/location-eintragen`, `/sammelanfrage`, `/impressum` and `/inhalt-melden`. Results land in `var/lighthouse/` (`perf-<stamp>-<NAME>-<run>.report.json`) and `var/perf/`. Run the Lighthouse pages in one session, because the day seed moves the first card at midnight Zurich. k6 is not simulated: a k6 result is conclusive only at a load average under 4 (`uptime`). A miss above it stays BLOCKED until an idle run, so wait for one, and record the load either way. The perf stack is one Compose project for every lane: check `docker ps | grep deine-eventlocation-perf` first and run `make perf-down` when done. `make perf-up` can leave nginx stopped under load, because its `restart php nginx` races and nginx exits on `host not found in upstream "php"`. Run it again.
