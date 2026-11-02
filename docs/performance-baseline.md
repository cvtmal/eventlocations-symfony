# Performance baseline

_Last updated: 2026-11-02 · Source: `docs/rpi-cycles.md` #19, #41, S10, S11 (PERF-1)_

## 1. Summary

**After R2 (#41), 2026-11-02**

- **One Lighthouse row misses: the canton page's LCP on real data, 2,331 ms against 2,000.** One venue's 44 KB first photo is the sole cause (§8). The rule for it is a re-run on the next day's seed, 3 November, so the row reads "miss, one venue's 44 KB photo, next seed owed". Every other Lighthouse and byte budget holds on demo ×1 and real ×1, with the filter state's SEO 69 as the expected exception (`is-crawlable`, `noindex, follow` by design).
- **Listing and canton page can each cross 2,000 ms on some days.** On real data the simulated LCP tracks the first card photo's bytes, and the day seed picks that photo: an 18 KB photo gives 1,732 ms, 28 KB 1,953 ms, 44 KB 2,404 ms (§8).
- **Two fixes closed the other real-data misses** (§8): `fetchpriority="low"` on the second card's eager photo, and `alt=""` on card slides 2–8. Real listing HTML went from 73,084 B to 54,144 B gzip.
- **k6 on demo ×10 is inconclusive, not a pass.** Both runs exited 2 at load average 7 to 11, and a third was stopped at about 20. A miss at load above 4 is inconclusive. `make perf-run MODE=all` at load under 4 has not run and is owed. k6 on real ×1 is within every budget (§5). All k6 figures predate the two fixes.

**After #19, 2026-10-31**

- **Every PERF-1 budget holds on the local perf stack**, before and after #19, with one expected exception: the filtered listing scores SEO 66, because a filter state is `noindex, follow` by design (#16). The only failing audit is `is-crawlable`.
- **HttpCache takes the server time of a repeat view from about 40 ms to about 3 ms** (p50) on demo data ×10. Cached p95 is 5.8–7.9 ms against a budget of 20 ms.
- **Lighthouse barely moves, and it cannot show the cache.** Lantern models one median server response time over all of a page's requests, so the document's own TTFB is averaged away among the static files and the simulated LCP stays within 1 ms of the baseline. The recorded "After" medians also had run 1 of each page as a miss, because the warm-up used another cache key (§4). The gain is real server time (§5) and, in production, a Neon database that stays asleep.
- **A purge under load gives no error; a full clear can.** A CMS purge removes only the store's metadata, and 1,063 purges during 24,176 requests at 403 requests/s gave 0 errors and 0 short bodies. `app:http-cache:clear` also drops the bodies, and a request that looked up a body just before answers Symfony's raw 500 page: about 1–3.5×10⁻⁴ per clear per request/s (31 in 716 clears at 401 requests/s here; the Review measured 21 and 8 in two runs, the re-review 51 in 677 clears at 221 requests/s), so at 1 request/s one 500 in about 3,000–9,000 clears. It runs nightly and from the size guard only. The earlier "0 failed in 396 clears" checked status codes on a run of at most 20 requests/s, where at most about 1.4 errors were expected (§5).
- **No PERF-1 fix was needed.** Nothing in the conditional fix list (fonts, `sizes`, critical CSS, query tuning, the front-controller fallback) was triggered. See §8.

| Budget (S11) | Limit | Before | After | After R2 (#41), demo | After R2 (#41), real | Pass, R2 |
|---|---|---|---|---|---|---|
| LCP, home | ≤ 2.0 s | not measured | not measured | 1,353 ms | 1,507 ms | yes |
| LCP, listing | ≤ 2.0 s | 1,880 ms | 1,879 ms | 1,729 ms | 1,884 ms | yes |
| LCP, three filters | ≤ 2.0 s | 1,728 ms | 1,728 ms | 1,588 ms | 1,957 ms | yes |
| LCP, canton page | ≤ 2.0 s | 1,728 ms | 1,729 ms | 1,579 ms | 2,331 ms | miss, one venue's 44 KB photo, next seed owed |
| LCP, rich detail page | ≤ 2.0 s | 1,354 ms | 1,353 ms | 1,429 ms | 1,655 ms | yes |
| CLS, worst page | ≤ 0.05 | 0.026 | 0.026 | 0.002 | 0.002 | yes |
| TBT, worst page | ≤ 100 ms | 0 ms | 0 ms | 0 ms | 25 ms | yes |
| Lighthouse scores | ≥ 95 each | 99–100, SEO 66 on the filter state | 99–100, SEO 66 on the filter state | 99–100, SEO 69 on the filter state | 98–100, SEO 69 on the filter state | yes, the filter state by design |
| Uncached p95, `/` and listing pages | ≤ 200 ms | 39.2–54.8 ms | 52.6–74.6 ms | 54.3–184.8 ms | 79.2–123.7 ms | demo inconclusive under load, real yes |
| Uncached p95, detail | ≤ 120 ms | 53.3 ms | 80.2 ms | 125.0, then 104.5 ms | 72.0 ms | demo inconclusive under load, real yes |
| Cached p95, all pages | ≤ 20 ms | no cache | 5.8–7.9 ms | 8.4–21.0 ms | not run | demo inconclusive under load |
| CSS gzip | ≤ 25 KB | 10,109 B | 10,109 B | 13,801 B | same file | yes |
| JS gzip per page | ≤ 15 KB | 14,542 B | 14,542 B, 14,634 B after #37 | 14,726 B | same files | yes |
| Listing HTML gzip | ≤ 60 KB | 51,434 B | 51,434 B | 52,203 B fixture | 54,144 B | yes |
| Canton page HTML gzip (the test's limit) | ≤ 60 KB | not listed | 51,568 B | 52,324 B fixture | 54,401 B | yes |
| Font files on first view | ≤ 2 | 2 | 2 | 1 | 1 | yes |

Before is Phase 0, without HttpCache. After is #19's finished cycle. After R2 is #41's tree, base `12a62d3`, with Lighthouse on demo ×1 and real ×1. Its k6 figures are demo ×10 and real ×1, measured at load average 7 to 17 and before the two fixes of §8. The real canton page's first photo is venue 62's. These are local numbers, not Hostpoint and Neon (§9).

## 2. Setup

- **Stack.** `compose.perf.yaml`, Compose project `hochzeitslocation-perf` on :8096 and :5446, next to the dev lane:
  - `APP_ENV=prod`, `APP_DEBUG=0`. Composer runs `--no-dev --classmap-authoritative`; `cache:clear`, `sass:build` and `asset-map:compile` run in the container.
  - Xdebug is off. OPcache has `validate_timestamps=0`, so a code change needs `make perf-up`.
  - php-fpm uses a static pool of 10 children.
  - Postgres 18 runs with `pg_stat_statements` and `shared_buffers=512MB`.
  - `vendor/`, `var/` and `public/assets/` are named volumes. `public/media` is bind-mounted, so the photos are real.
- **Size.** nginx, php and Postgres share two cores (`cpuset: "0,1"`), with memory limits of 256 MB, 1.5 GB and 2 GB. Peak in the cached run: php 23 % CPU and 100 MiB, Postgres 24 % and 115 MiB, nginx 3 % and 5 MiB.
- **Host.** Apple M4 Pro; the Docker VM has 14 CPUs and 11.4 GiB. Other lanes ran on the same machine, so the k6 figures carry some noise (§5).
- **Commit.** #19: base `03d1618`, plus that cycle's change. #41: base `12a62d3`, plus that cycle's change up to its Step 5.2.

## 3. Datasets

| Used for | Data | Venues | Photos |
|---|---|---|---|
| Lighthouse | Demo ×1, with real image files | 60 | 390 |
| k6 | Demo ×10, cloned by `tests/Load/times-ten.sql` | 600 | 3,620 venue photos |
| Lighthouse and k6 `MODE=uncached`, #41 | Real data ×1, imported into the lane | 243 | 2,141 |

All come from a `pg_dump` of the lane. `tests/Load/live-canton.sql` then moves 2 venues to Zürich in the perf database only, because demo ×1 has no canton page at prod's threshold of 5 venues. ×10 is cloned from that data, so Zürich has 50 venues there. Cloned photos have no files, so Lighthouse never runs on ×10. Real data has 52 published venues in Zürich, so the script moves nothing there.

## 4. Lighthouse

Lighthouse 13.5.0, mobile, simulated slow 4G, median of 3 runs, on the perf stack.

### After R2 (#41)

Measured after the two fixes of §8, in one session on 2026-11-02, so one day seed: real 02:51 to 02:55 at load average 11 to 17, demo 02:56 to 03:00 at 10 to 23 (Europe/Zurich).

**Demo ×1**

| Page | Perf | A11y | BP | SEO | LCP | CLS | TBT | FCP | LCP element |
|---|---|---|---|---|---|---|---|---|---|
| `/` | 100 | 100 | 100 | 100 | 1,353 ms | 0.000 | 0 ms | 950 ms | hero photo (640w AVIF) |
| `/hochzeitslocations` | 99 | 100 | 100 | 100 | 1,729 ms | 0.000 | 0 ms | 1,450 ms | first card photo (640w AVIF) |
| `?canton=zh&guests=80&setting[]=countryside` | 100 | 100 | 100 | 69 | 1,588 ms | 0.000 | 0 ms | 1,297 ms | first card photo |
| `/hochzeitslocations/zuerich` | 100 | 100 | 100 | 100 | 1,579 ms | 0.000 | 0 ms | 1,260 ms | first card photo |
| `/hochzeitslocation/zunftsaal-erlenhof` | 100 | 100 | 100 | 100 | 1,429 ms | 0.002 | 0 ms | 1,116 ms | first gallery slide (960w AVIF) |

**Real ×1**

| Page | Perf | A11y | BP | SEO | LCP | CLS | TBT | FCP | LCP element | LCP image |
|---|---|---|---|---|---|---|---|---|---|---|
| `/` | 100 | 100 | 100 | 100 | 1,507 ms | 0.000 | 0 ms | 971 ms | hero photo | 27,067 B |
| `/hochzeitslocations` | 99 | 100 | 100 | 100 | 1,884 ms | 0.000 | 25 ms | 1,429 ms | first card photo (640w AVIF) | 24,998 B |
| `?canton=zh&guests=80&price[]=medium` | 99 | 100 | 100 | 69 | 1,957 ms | 0.000 | 2 ms | 1,422 ms | first card photo | 27,985 B |
| `/hochzeitslocations/zuerich` | 98 | 100 | 100 | 100 | 2,331 ms | 0.000 | 14 ms | 1,431 ms | first card photo, venue 62 | 44,121 B |
| Detail, richest real venue (venue 102, 13 photos) | 100 | 100 | 100 | 100 | 1,655 ms | 0.002 | 0 ms | 1,123 ms | first gallery slide (640w AVIF) | 31,244 B |

The real filter differs from demo's, so its row is not like for like. In the filter state, `is-crawlable` is the only failing SEO audit on both datasets. Before the two fixes, real LCP read 2,104 ms on the listing, 2,703 ms with three filters and 2,405 ms on the canton page.

Transfer of the median run, in bytes, headers included:

| Page | Demo document | Demo images | Real document | Real images | CSS | JS | Fonts |
|---|---|---|---|---|---|---|---|
| Home | 6,560 | 19,022 | 6,765 | 110,990 | 14,776 | 4,876 | 20,450 |
| Listing | 58,408 | 137,898 | 56,723 | 1,348,481 | 14,776 | 14,407 | 20,450 |
| Three filters | 22,111 | 30,727 | 46,375 | 1,707,471 | 14,776 | 14,407 | 20,450 |
| Canton page | 25,697 | 110,508 | 56,902 | 1,454,290 | 14,776 | 14,407 | 20,450 |
| Detail | 11,811 | 39,220 | 11,517 | 310,710 | 14,776 | 11,193 | 20,450 |

### History: #19, demo ×1

**After (HttpCache on)**

Run 1 of each page below was a miss, not a hit: the warm-up sent `Host: nginx` and Lighthouse `Host: localhost`, a different store key, so only runs 2 and 3 came from the cache. `make perf-lighthouse` now warms with `Host: localhost`, so future runs are all hits. The medians would not move, for the reason in §1.

| Page | Perf | A11y | BP | SEO | LCP | CLS | TBT | FCP | LCP element |
|---|---|---|---|---|---|---|---|---|---|
| `/hochzeitslocations` | 99 | 100 | 100 | 100 | 1,879 ms | 0.026 | 0 ms | 1,654 ms | first card photo (960w AVIF) |
| `?canton=zh&guests=80&setting[]=countryside` | 99 | 100 | 100 | 66 | 1,728 ms | 0.026 | 0 ms | 1,653 ms | first card photo |
| `/hochzeitslocations/zuerich` | 99 | 100 | 100 | 100 | 1,729 ms | 0.024 | 0 ms | 1,654 ms | first card photo |
| `/hochzeitslocation/zunftsaal-erlenhof` | 100 | 100 | 100 | 100 | 1,353 ms | 0.000 | 0 ms | 1,052 ms | first gallery slide (960w AVIF) |

**Before (no HttpCache)**

| Page | Perf | A11y | BP | SEO | LCP | CLS | TBT | FCP |
|---|---|---|---|---|---|---|---|---|
| `/hochzeitslocations` | 99 | 100 | 100 | 100 | 1,880 ms | 0.026 | 0 ms | 1,655 ms |
| `?canton=zh&guests=80&setting[]=countryside` | 99 | 100 | 100 | 66 | 1,728 ms | 0.026 | 0 ms | 1,653 ms |
| `/hochzeitslocations/zuerich` | 99 | 100 | 100 | 100 | 1,728 ms | 0.024 | 0 ms | 1,503 ms |
| `/hochzeitslocation/zunftsaal-erlenhof` | 100 | 100 | 100 | 100 | 1,354 ms | 0.000 | 0 ms | 1,097 ms |

Transfer of the median run after #19, in bytes:

| Page | Document | CSS | JS | Images | Fonts |
|---|---|---|---|---|---|
| Listing | 58,660 | 10,803 | 14,223 | 198,495 | 31,600 |
| Three filters | 21,700 | 10,803 | 14,223 | 35,253 | 31,600 |
| Canton page | 25,339 | 10,803 | 14,223 | 156,971 | 31,600 |
| Detail | 11,338 | 10,803 | 11,009 | 31,534 | 31,600 |

These run a little higher than the baseline because each asset now carries a `Cache-Control` header. Transfer counts response headers; §7 counts file content.

## 5. Server time (k6)

### After R2 (#41)

All of these predate the two fixes of §8. None is comparable with #19's figures, because other lanes loaded the host (§9).

**Demo ×10, `make perf-run MODE=all`: inconclusive under load.** Both runs exited 2. A miss at load average above 4 is inconclusive, not a miss, so nothing was tuned on these figures. A bold figure failed its threshold.

| Page | Uncached p95, run 1 | Uncached p95, run 2 | Budget | Cached p95, run 1 | Cached p95, run 2 | Budget |
|---|---|---|---|---|---|---|
| home | 54.3 ms | 69.5 ms | 200 ms | 8.8 ms | 19.1 ms | 20 ms |
| listing | 134.5 ms | 157.2 ms | 200 ms | 14.1 ms | **21.0 ms** | 20 ms |
| three filters | 106.3 ms | 89.3 ms | 200 ms | 11.7 ms | 15.7 ms | 20 ms |
| canton page | 184.8 ms | 147.3 ms | 200 ms | 15.4 ms | 18.4 ms | 20 ms |
| detail | **125.0 ms** | 104.5 ms | 120 ms | 11.6 ms | 8.4 ms | 20 ms |

Load average: run 1, after it, 10.27 / 10.09 / 10.83. Run 2, before it, 11.40 / 10.39 / 10.93; after it, 7.08 / 9.49 / 10.31. A third run was stopped at 19.20 with no figures. Both finished runs: 0 `server_errors`, 0 `short_bodies`, 0 failed requests, `cache_fresh` 100 %.

`make perf-run MODE=all` at load under 4 has not run and is owed.

**Real ×1, `make perf-run MODE=uncached`: exit 0.** Load average 17.27 before, 7.79 after. 1,757 requests, 0 failed, 0 `server_errors`, 0 `short_bodies`.

| Page | p50 | p95 | p99 | Budget |
|---|---|---|---|---|
| home | 21.1 ms | 84.2 ms | 186.6 ms | 200 ms |
| listing | 40.3 ms | 103.1 ms | 236.9 ms | 200 ms |
| three filters | 39.3 ms | 123.7 ms | 310.3 ms | 200 ms |
| canton page | 40.0 ms | 79.2 ms | 140.5 ms | 200 ms |
| detail | 30.0 ms | 72.0 ms | 128.5 ms | 120 ms |

### History: #19

k6 2.3.0 over the Compose network, demo ×10, `make perf-run MODE=all`. Uncached: 60 s at 5 requests/s per page after a 10 s warm-up, each request with a unique `k6=` query, so it is a backend render plus a store write. Cached: 30 s at 20 requests/s per page, after one priming request. 3,815 requests, 0 failed, every check passed. `cache_fresh` was 2,402 of 2,402 in the measured phase.

**Uncached, after**

| Page | p50 | p90 | p95 | p99 | max | Requests | Budget |
|---|---|---|---|---|---|---|---|
| listing | 40.8 ms | 60.2 ms | 72.6 ms | 103.4 ms | 208.5 ms | 301 | p95 ≤ 200 ms |
| three filters | 30.4 ms | 42.5 ms | 52.6 ms | 86.7 ms | 144.1 ms | 300 | p95 ≤ 200 ms |
| canton page | 47.3 ms | 62.7 ms | 74.6 ms | 94.3 ms | 148.4 ms | 300 | p95 ≤ 200 ms |
| detail | 45.8 ms | 71.6 ms | 80.2 ms | 109.1 ms | 174.3 ms | 300 | p95 ≤ 120 ms |

**Uncached, before (no store write)**

| Page | p50 | p95 | p99 | max |
|---|---|---|---|---|
| listing | 39.2 ms | 54.8 ms | 76.7 ms | 128.5 ms |
| three filters | 26.2 ms | 39.2 ms | 63.6 ms | 73.9 ms |
| canton page | 40.8 ms | 54.7 ms | 87.0 ms | 148.4 ms |
| detail | 38.9 ms | 53.3 ms | 71.4 ms | 105.0 ms |

**Cached, after**

| Page | p50 | p90 | p95 | p99 | max | Requests | Budget |
|---|---|---|---|---|---|---|---|
| listing | 2.8 ms | 5.7 ms | 6.8 ms | 12.5 ms | 42.2 ms | 601 | p95 ≤ 20 ms |
| three filters | 2.5 ms | 4.7 ms | 5.8 ms | 10.5 ms | 36.5 ms | 600 | p95 ≤ 20 ms |
| canton page | 2.7 ms | 5.2 ms | 6.2 ms | 8.9 ms | 17.3 ms | 600 | p95 ≤ 20 ms |
| detail | 5.2 ms | 6.8 ms | 7.9 ms | 14.5 ms | 30.3 ms | 601 | p95 ≤ 20 ms |

- **A miss costs a little more than before, and most of the p95 gap is noise.** Phase 0 and this run had different load on the host. The Review ran HttpCache on against off on the same stack in the same hour, alternating: p50 +0.2 to +5.0 ms, and p95 varied more between identical runs (detail off 47.9 and 77.3 ms) than between on and off. So the store costs 0–5 ms at p50, and the store write cannot be separated from the lookup. The p95s 62.1, 53.1, 63.5 and 70.1 ms once given here as a "second run" are the clear-under-load run below, not a repeat. The budget has room either way.
- **A clear under load.** The first run (`app:http-cache:clear` about once a second, 396 clears, during `MODE=all`) reported 3,817 requests and 0 failed, but it checked only status codes and ran at most 20 requests/s, where the rate below predicts at most about 1.4 errors, so its zero says little. Cached p95 rose to 30.8–49.0 ms with a clear every second, and k6 exits 2 for that reason. Measured since with a body-length check (`var/review/body.js`, 16 clients on the listing, the detail page and `/impressum`, 60 s and 40 s):

  | Clear loop | Clears | Load | 500s | Short bodies |
  |---|---|---|---|---|
  | Purge path (`invalidate()`, `md/` only), tight loop | 1,063 | 403 req/s | 0 | 0 |
  | `clear()` (whole store), tight loop | 716 | 401 req/s | 31 | 0 |
  | `clear()`, Review, tight loop | 688 | 158 req/s | 21 | 0 |
  | `clear()`, Review, `app:http-cache:clear` every second | 40 | 1,191 req/s | 8 | 0 |

  A 500 is Symfony's raw 1,017 B "An Error Occurred" page without SEC-1 headers: the request found the body path in `Store::lookup()`, the clear removed it, and `file_get_contents()` warned before the kernel ran. The `clear()` rows fit about 1.1–2×10⁻⁴ per clear per request/s, and the re-review measured 3.4×10⁻⁴ (51 in 677 clears at 221 requests/s), so this doc takes about 1–3.5×10⁻⁴ per clear per request/s (at 1 request/s, one 500 in about 3,000–9,000 clears). Accepted, because only the nightly cron and the size guard call `clear()`. A purge leaves the bodies, which are content-addressed and never rewritten, so a new metadata entry points at the same paths and the race has nothing to remove. `tests/Load/pages.js` now counts `server_errors` and `short_bodies` and fails above 0.

## 6. Queries per page

| Page | Uncached | Cached |
|---|---|---|
| Listing | 7 | 0 |
| Canton or building page | 7 | 0 |
| Town page | 8 | 0 |
| Detail | 5, plus the similar-venues fragment | 0 |

Top SQL at ×10: `SimilarVenuesQuery` averages 23.3 ms and is the largest single cost of an uncached detail page. It has not been tuned, because the page is inside its budget. Listing queries stay under 20 ms each (`ListingQueryPerformanceTest`).

## 7. Byte budgets

`make perf-check`, also in CI, on #41's tree after the fixes of §8. The test's HTML fixture cannot see real markup (§9), so the real rows are measured on the perf stack and are not part of the check.

| Budget | Figure | Limit |
|---|---|---|
| Listing HTML, 48 cards × 8 photos, full overlay | 52,203 B gzip (777,483 raw) | 61,440 |
| Canton page HTML, same test | 52,324 B gzip (778,398 raw) | 61,440 |
| Home HTML, same test | 6,385 B gzip (38,784 raw) | 61,440 |
| Listing HTML, real ×1 | 54,144 B gzip | 61,440 |
| Canton page HTML, real ×1 | 54,401 B gzip | 61,440 |
| `app.css`, as the test measures it | 13,801 B gzip, 13,857 B as shipped | 25,600 |
| JS, all of `assets/js/*.js` | 14,726 B gzip | 15,360 |
| `overlay.js + live-count.js + region-filter.js` | 6,132 B gzip | 6,144 |
| `carousel.js` | 938 B gzip | 2,048 |
| Font files on first view | 1 | 2 |
| Admin shared JS, each island, admin CSS | pass (`AdminAssetBudgetTest`) | 60, 30, 30 KB gzip |

## 8. Fixes

### #41 (R2)

**The finding.** On real data, LCP under Lighthouse's simulation tracks the first card photo's bytes. Same template, nearly the same document, after both fixes:

| First card photo | Page | LCP, simulated |
|---|---|---|
| 18 KB | Category landing page, 48 cards | 1,732 ms |
| 25 KB | Listing | 1,881 ms |
| 28 KB | Three filters | 1,953 ms |
| 44 KB | Canton page | 2,404 ms |

The day seed picks that photo, so **listing and canton page can each cross 2,000 ms on some days**: 18 KB holds at 1,732 ms, 28 KB at 1,953 ms, and 44 KB misses at 2,404 ms. The crossing lies between 28 and 44 KB, at a point not measured, and the large end rests on the canton page alone. 640w AVIFs run past 60 KB. Unthrottled, the observed LCP in these runs is 115–230 ms, so this is the simulated line, not a slow page.

The same photos measured 1,884, 1,957 and 2,331 ms in the full re-measure of §4. Since fix 1 the 44 KB page has ranged from 2,328 to 2,406 ms across three sessions, and it missed in all of them.

| Candidate | Result |
|---|---|
| LCP image, second card's eager photo | **Fix 1.** It was the page's one Medium image, started with the LCP image, and its size followed the miss. It now prints `fetchpriority="low"` and stays eager. Listing 2,104 to 2,029 ms, three filters 2,703 to 1,955 ms, canton page 2,405 to 2,328 ms. The gain is mostly how the simulator counts; the field gain is unmeasured |
| Listing and canton HTML | **Fix 2.** Card slides 2–8 print `alt=""`, because their links are `aria-hidden` with `tabindex="-1"` and no reader meets them. Real listing 73,084 to 54,144 B gzip, canton page 71,782 to 54,401 B. It costs the listing's image search for those slides; the detail gallery keeps the alts. Accessibility stays 100 |
| Canton page LCP on real data | No code fix: miss, one venue's 44 KB photo, next seed owed. Venue 62. The file cannot shrink by code, as there is no variant between 360 and 640 without regenerating 2,141 photos |
| AVIF quality | Not changed. Open with the owner: a lower quality would shrink these photos and changes the look |
| Fonts | None needed: the LCP element is an image on all five pages |
| `sizes` | None needed: drawn 332 px, DPR 1.75, 581 px wanted, 640w picked |
| Critical CSS | None needed: the CSS is not what misses |
| Query tuning | None needed: real k6 is within budget, demo k6 is inconclusive under load (§5) |
| CLS, TBT, scores | None needed |

### History: #19

| Candidate | Result |
|---|---|
| HttpCache in prod | Done, the point of the cycle: p50 about 40 ms to about 3 ms on a repeat view |
| Compression | Confirmed on the perf stack: `Content-Encoding: gzip`, `Vary: Accept-Encoding`, from nginx in front of HttpCache. Production is #20's check |
| Listing LCP image | Confirmed: the first card is the LCP element and the page's only `fetchpriority="high"` |
| Second font preload | Not needed: no LCP or CLS miss |
| Detail `sizes` | Not needed: no LCP miss, no failing image audit |
| Critical CSS | Not needed: LCP is within budget |
| Query tuning | Not needed: uncached p95 is within budget |
| HttpCache in `public/index.php` | Not needed: cached p95 is under 20 ms with `framework.http_cache` |

## 9. Caveats

- **A loaded host (#41).** Other lanes ran on the machine during every #41 run: load average 7 to 23. Lighthouse is simulated throttling, so its figures stand, with TBT spikes in single runs. k6 is not simulated, so #41's figures are not comparable with #19's (§5).
- **The day seed moves the first card.** The listing pages order by a seed that changes at midnight Zurich, so a real LCP figure holds for its date, here 2026-11-02 (§8).
- **Real data has no rooms and no rating.** Real cards and detail pages are lighter in those parts than demo's, and demo stays the rich shape.
- **The lab has only DejaVu.** The Lighthouse image has no Arial and no metric twin such as Liberation Sans, so fallback text there is not what a visitor sees before Figtree arrives.
- **The HTML budget test cannot see real markup.** Its fixture repeats a constant alt, which compresses far better than real text: 52,427 B against the real listing's 73,084 B before fix 2, with `make perf-check` passing.
- **Local, not production.** Two fast local cores and a Postgres in the same Docker VM stand in for Hostpoint and Neon. Compression, Lighthouse on the real domain and a Neon cold start are #20's addendum.
- **Lighthouse does not show the cache.** Lantern averages the document's TTFB among all requests (§1), so the simulated LCP cannot move. Under applied throttling the Review saw the hit (listing TTFB 59–107 → 15–21 ms) swamped by about ±300 ms of LCP noise. k6 (§5) is the evidence for what caching changed. Expect a larger difference in production, where a miss may also wake Neon.
- **The store has a size bound, not only a time bound.** `Store` never evicts, and every filter state, `?q=` value and unknown slug is a new key: an anonymous client wrote up to 20.9 MiB/s here (Review, F-M1). `app:http-cache:clear --if-over-limit` on #20's 5-minute cron clears it above 5 GiB or 1,000,000 files; the store can overshoot by one interval of write rate. Measured scaled down by 10 (512 MiB, 100,000 files, every 30 s; `var/rework/guard/`): under the worst shape (`?building[]=` subsets, 50 req/s, 25.5 MiB/s into the store) the store peaked at 1,068 MiB, the limit plus 0.73 of one interval's writes, and the guard cleared on 9 of 10 runs; `?q=` peaked at 665 MiB, `404`s at 36k files; 0 errors in 62,256 requests, no leftover copy. At the local building rate production's guard would peak near 5 GiB + 7.5 GiB. The walk costs about 4–9 µs per file plus about 50 ms of console start-up (about 4–9 s at 1,000,000 files, extrapolated). The byte limit counts file bytes, not disk blocks: small entries took about 6.4 KB of disk for 1.1 KB of bytes, so for them the file limit is the bound (1,000,000 files, about 6.4 GB on disk).
- **A render in flight can pin an old page.** A miss that read the database before a CMS commit and stores after the purge keeps the old page until the next `clear()`, so until 00:05 at the latest. The window is one render (40–80 ms). Accepted; the bound depends on #20's nightly cron.
- **The store's three failure modes.** `var/share/<env>` not writable: 500 on every URL. A store that exists but is not writable: a new URL `miss/store-failed`, a URL with a stale entry 500 (`Store::lock()` flocks a failed `fopen()`). And the `clear()` race above. Nothing but the web user may write the store.
- **`var/share/<env>` must be writable by the web user.** On the first run the store directory could not be created, and HttpCache answered 500 on every URL, `/health` included. `make perf-up` and `make perf-seed` now hand `var` to `www-data`. #20 must check the same on Hostpoint.
- **The baseline ran with that same problem in its filesystem pools.** They were root-owned, so the footer's canton list was probably never written; that is inferred from the ownership, not measured on the baseline. The after-run's footer does carry the list. The listing's document grew by 172 B in the after-run, and this probably explains at least part of it.
- **The fill-time token is shared.** A cached detail page gives every visitor the same `inquiry[started]` value, so the bot rule weakens to "at least 3 s since that page was rendered". Accepted (research D4).
- **A filter state stays at SEO 66 in #19 and 69 in #41.** This is `noindex, follow` doing its job, not a defect.

## 10. How to re-run

```sh
make perf-up                           # start and prepare; rerun after every code change
make perf-seed                         # demo ×1, for Lighthouse
make perf-lighthouse URL=/hochzeitslocations NAME=listing
make perf-seed TIMES=10                # demo ×10, for k6
make perf-run MODE=all                 # uncached and cached; MODE=uncached skips the cached half
make perf-down                         # stop and delete the volumes
make perf-check                        # byte budgets; needs no perf stack
```

Real data, once the lane holds it (#41). `perf-seed` copies the lane's active database, and both commands print a slug, so their output stays in a file under `var/`:

```sh
make perf-up
make perf-seed                         # real ×1, for Lighthouse and k6
make perf-lighthouse URL="$(sed -n 2p var/shots/real-urls.txt)" NAME=real-listing > var/perf/real-listing.log 2>&1
FILTERS='canton=zh&guests=80&price[]=medium' CANTON=zuerich make perf-run MODE=uncached > var/perf/real-k6.log 2>&1
```

Run all five real pages in one session, because the day seed moves the first card at midnight Zurich. k6 budgets stay on demo ×10; real ×1 is recorded beside them, and a k6 miss counts only at load average under 4. `make perf-up` can leave nginx stopped under load: its `restart php nginx` races, and nginx exits on `host not found in upstream "php"`. Run it again.

The lane must be freshly seeded (`make db-fresh && make seed`), because `perf-seed` copies its database. Results land in `var/perf/` and `var/lighthouse/`. To test a clear under load, run `app:http-cache:clear` in a loop in the perf `php` container as `www-data` during `make perf-run MODE=all`. Expect k6 to exit 2 then, from the cached p95 thresholds and from `server_errors` when a clear hits a body read (§5). `make perf-up` and `make perf-seed` empty the store, so a re-run never serves the previous code's or dataset's pages; `make perf-lighthouse` warms with Lighthouse's `Host`, so every run is a hit.
