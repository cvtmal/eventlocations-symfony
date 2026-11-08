# Performance baseline

_Last updated: 2026-11-02 · Source: `docs/rpi-cycles.md` #19, #41, S10, S11 (PERF-1)_

## 1. Summary

**After R2 (#41), final tree, 2026-11-02 15:17-15:42 (D15 re-measure after R1: hero slides 2+ `fetchpriority="low"`, `carousel.js` paging)**

- **One Lighthouse row misses: real canton, 2,328 ms against 2,000.** The known miss: one venue's 44,121 B first photo on the day seed, the page's only non-Low image in all three runs. Ruling -34: accepted, the re-run on another day's seed goes to #20 Step 9.3 (field LCP). **The real detail page now passes at 1,726 ms** (runs 1,726, 1,727, 1,726), down from F6's 2,628 ms. Its bimodal runs came from hero slide 2, a lazy 105 KB AVIF whose box reaches 16 px into the phone viewport: Chrome rated it High in every slow run, and Lantern ends LCP at the last non-Low image. R1 prints `fetchpriority="low"` on slides 2+ (§8 Fix 3); slide 1 (31,244 B, High) is now the only non-Low image. Every other Lighthouse budget holds on demo ×1 and real ×1. The filter state's SEO 69 is the expected exception (`is-crawlable`, `noindex, follow` by design).
- **k6 passes every budget.** On demo ×10, `make perf-run MODE=all` exits 0, started at load 3.4 after a 120 s wait for a load under 4, 3.2 at its end. Uncached p95 34.3 to 61.3 ms (detail 55.6 against 120), cached p95 6.7 to 8.6 ms, 0 `server_errors`, 0 `short_bodies`. On real ×1, `MODE=uncached` exits 0 with p95 28.1 to 50.3 ms (§5).
- **Byte budgets:** `make perf-check` exits 0 on the final tree (R1, and again in the re-review). §7's figures are re-read on it: the JS rows by R1, the HTML fixture rows by the re-review with `SEARCH_PERF_REPORT=1` (plan Step 3.3's command, which prints them) and `app.css` by `AssetBudgetTest`'s method. Only the two real ×1 HTML rows are older: they need the perf stack and come from Step 5.1's tree (§7).
- Three fixes apply (§8): `fetchpriority="low"` on the second card's eager photo, `alt=""` on card slides 2–8, and (R1) `fetchpriority="low"` on the detail hero's slides 2+.

**After #19, 2026-10-31**

- **Every PERF-1 budget holds on the local perf stack**, before and after #19, with one expected exception: the filtered listing scores SEO 66, because a filter state is `noindex, follow` by design (#16). The only failing audit is `is-crawlable`.
- **HttpCache takes the server time of a repeat view from about 40 ms to about 3 ms** (p50) on demo data ×10. Cached p95 is 5.8–7.9 ms against a budget of 20 ms.
- **Lighthouse barely moves, and it cannot show the cache.** Lantern models one median server response time over all of a page's requests, so the document's own TTFB is averaged away among the static files and the simulated LCP stays within 1 ms of the baseline. The recorded "After" medians also had run 1 of each page as a miss, because the warm-up used another cache key (§4). The gain is real server time (§5) and, in production, a Neon database that stays asleep.
- **A purge under load gives no error; a full clear can.** A CMS purge removes only the store's metadata, and 1,063 purges during 24,176 requests at 403 requests/s gave 0 errors and 0 short bodies. `app:http-cache:clear` also drops the bodies, and a request that looked up a body just before answers Symfony's raw 500 page: about 1–3.5×10⁻⁴ per clear per request/s (31 in 716 clears at 401 requests/s here; the Review measured 21 and 8 in two runs, the re-review 51 in 677 clears at 221 requests/s), so at 1 request/s one 500 in about 3,000–9,000 clears. It runs nightly and from the size guard only. The earlier "0 failed in 396 clears" checked status codes on a run of at most 20 requests/s, where at most about 1.4 errors were expected (§5).
- **No PERF-1 fix was needed.** Nothing in the conditional fix list (fonts, `sizes`, critical CSS, query tuning, the front-controller fallback) was triggered. See §8.

| Budget (S11) | Limit | Before | After | After R2 (#41), demo | After R2 (#41), real | Pass, R2 |
|---|---|---|---|---|---|---|
| LCP, home | ≤ 2.0 s | not measured | not measured | 1,353 ms | 1,503 ms | yes |
| LCP, listing | ≤ 2.0 s | 1,880 ms | 1,879 ms | 1,727 ms | 1,878 ms | yes |
| LCP, three filters | ≤ 2.0 s | 1,728 ms | 1,728 ms | 1,652 ms | 1,954 ms | yes |
| LCP, canton page | ≤ 2.0 s | 1,728 ms | 1,729 ms | 1,652 ms | 2,328 ms | miss on real, accepted (ruling -34): one venue's 44 KB photo on the day seed; field check in #20 Step 9.3 |
| LCP, rich detail page | ≤ 2.0 s | 1,354 ms | 1,353 ms | 1,504 ms | 1,726 ms (F6: 2,628 ms; §8 Fix 3) | yes |
| CLS, worst page | ≤ 0.05 | 0.026 | 0.026 | 0.002 | 0.002 | yes |
| TBT, worst page | ≤ 100 ms | 0 ms | 0 ms | 0 ms | 25 ms | yes |
| Lighthouse scores | ≥ 95 each | 99–100, SEO 66 on the filter state | 99–100, SEO 66 on the filter state | 99–100, SEO 69 on the filter state | 98–100, SEO 69 on the filter state | yes, the filter state by design |
| Uncached p95, `/` and listing pages | ≤ 200 ms | 39.2–54.8 ms | 52.6–74.6 ms | 34.3–61.3 ms | 28.1–50.3 ms | yes |
| Uncached p95, detail | ≤ 120 ms | 53.3 ms | 80.2 ms | 55.6 ms | 38.7 ms | yes |
| Cached p95, all pages | ≤ 20 ms | no cache | 5.8–7.9 ms | 6.7–8.6 ms | not run | yes |
| CSS gzip | ≤ 25 KB | 10,109 B | 10,109 B | 13,900 B | same file | yes |
| JS gzip per page | ≤ 15 KB | 14,542 B | 14,542 B, 14,634 B after #37 | 14,741 B | same files | yes |
| Listing HTML gzip | ≤ 60 KB | 51,434 B | 51,434 B | 52,169 B fixture | 54,144 B (Step 5.1's tree) | yes |
| Canton page HTML gzip (the test's limit) | ≤ 60 KB | not listed | 51,568 B | 52,285 B fixture | 54,401 B (Step 5.1's tree) | yes |
| Font files on first view | ≤ 2 | 2 | 2 | 1 | 1 | yes |

Before is Phase 0, without HttpCache. After is #19's finished cycle. After R2 is #41's final tree, `6186e51` plus F4's two CSS rules (`_overlay.scss`, `_filter-more.scss`) and R1's changes (hero slides 2+ `fetchpriority="low"`, `carousel.js` paging below md), re-measured by R1 in one session on 2026-11-02 between 15:17 and 15:42. Lighthouse ran on demo ×1 and real ×1, k6 on demo ×10 (`MODE=all`, load 3.4 at its start, 3.2 at its end) and real ×1 (`MODE=uncached`). The CSS and HTML fixture figures were re-read on the same tree in the re-review; the two real HTML rows are from Step 5.1's tree, after fixes 1 and 2 and before the move onto the portal, and were not re-read, because they need the perf stack (§7). The real canton page's first photo is venue 62's, and the real detail page is venue 102's. F6's figures from 14:30 to 14:55, before R1, are kept as history in §4 and §5. These are local numbers, not Hostpoint and Neon (§9).

## 2. Setup

- **Stack.** `compose.perf.yaml`, Compose project `deine-eventlocation-perf` on :8096 and :5446, next to the dev lane:
  - `APP_ENV=prod`, `APP_DEBUG=0`. Composer runs `--no-dev --classmap-authoritative`; `cache:clear`, `sass:build` and `asset-map:compile` run in the container.
  - Xdebug is off. OPcache has `validate_timestamps=0`, so a code change needs `make perf-up`.
  - php-fpm uses a static pool of 10 children.
  - Postgres 18 runs with `pg_stat_statements` and `shared_buffers=512MB`.
  - `vendor/`, `var/` and `public/assets/` are named volumes. `public/media` is bind-mounted, so the photos are real.
- **Size.** nginx, php and Postgres share two cores (`cpuset: "0,1"`), with memory limits of 256 MB, 1.5 GB and 2 GB. Peak in the cached run: php 23 % CPU and 100 MiB, Postgres 24 % and 115 MiB, nginx 3 % and 5 MiB.
- **Host.** Apple M4 Pro; the Docker VM has 14 CPUs and 11.4 GiB. Other lanes ran on the same machine, so the k6 figures carry some noise (§5).
- **Commit.** #19: base `03d1618`, plus that cycle's change. #41: `6186e51` (on `ef90eea`), plus F4's uncommitted CSS and R1's uncommitted hero, `carousel.js` and test changes, the final tree (R1, D15). R1's removal of a no-effect `outline-offset` in `_similar-venues.scss` came after its Lighthouse runs; `perf-check` covers it.

## 3. Datasets

| Used for | Data | Venues | Photos |
|---|---|---|---|
| Lighthouse | Demo ×1, with real image files | 60 | 390 |
| k6 | Demo ×10, cloned by `tests/Load/times-ten.sql` | 600 | 3,620 venue photos |
| Lighthouse and k6 `MODE=uncached`, #41 | Real data ×1, imported into the lane | 243 | 2,141 |

All come from a `pg_dump` of the lane. `tests/Load/live-canton.sql` then moves 2 venues to Zürich in the perf database only, because demo ×1 has no canton page at prod's threshold of 5 venues. ×10 is cloned from that data, so Zürich has 50 venues there. Cloned photos have no files, so Lighthouse never runs on ×10. Real data has 52 published venues in Zürich, so the script moves nothing there.

## 4. Lighthouse

Lighthouse 13.5.0, mobile, simulated slow 4G, median of 3 runs, on the perf stack.

### After #47 (venue-stats), demo ×1

`stats.js` on every public page (986 B gzip; page JS now measured per page, §7). One session on 2026-11-03: before on the unchanged tree `f8aaf05` at 17:57 to 17:59 (load average 15.6 to 7.8), after on #47's tree at 19:53 to 19:56 (5.9 to 4.4), Europe/Zurich. Every after-report lists the beacon's `/_stats` answered 204, so Lighthouse ran the script; `errors-in-console` passes in all 12 runs.

| Page | Before P/A/BP/SEO | Before LCP, TBT | After P/A/BP/SEO | After LCP, TBT |
|---|---|---|---|---|
| `/` | 100/100/100/100 | 1,352 ms, 0 ms | 100/100/100/100 | 1,359 ms, 0 ms |
| `/hochzeitslocations` | 99/100/100/100 | 1,727 ms, 0 ms | 99/100/100/100 | 1,728 ms, 11 ms |
| `/hochzeitslocations/zuerich` | 100/100/100/100 | 1,652 ms, 0 ms | 100/100/100/100 | 1,653 ms, 0 ms |
| Detail (venue 46) | 100/100/100/100 | 1,501 ms, 0 ms | 100/100/100/100 | 1,502 ms, 0 ms |

The listing's TBT runs were 13/9/11 ms after and 0/97/0 ms before; its 99 is LCP's, as before.

### After R1 (#41)

Final tree, one session on 2026-11-02: real 15:17 to 15:21 at load average 3.4 to 6.2, demo 15:26 to 15:30 at 5.0 to 6.1 (Europe/Zurich). Medians of 3; CLS is at most 0.002 everywhere. Detail is the richest venue by `run.sh`'s order: real venue 102 (13 photos), demo venue 46 (9 photos).

| Page | Demo P/A/BP/SEO | Demo LCP, TBT | Real P/A/BP/SEO | Real LCP, TBT |
|---|---|---|---|---|
| `/` | 100/100/100/100 | 1,353 ms, 0 ms | 100/100/100/100 | 1,503 ms, 0 ms |
| `/hochzeitslocations` | 99/100/100/100 | 1,727 ms, 0 ms | 99/100/100/100 | 1,878 ms, 11 ms |
| Three filters: demo `?canton=zh&guests=80&setting[]=countryside`, real `?canton=zh&guests=80&price[]=medium` | 100/100/100/69 | 1,652 ms, 0 ms | 99/100/100/69 | 1,954 ms, 25 ms |
| `/hochzeitslocations/zuerich` | 100/100/100/100 | 1,652 ms, 0 ms | 98/100/100/100 | **2,328 ms**, 3 ms |
| Detail | 100/100/100/100 | 1,504 ms, 0 ms | 100/100/100/100 | 1,726 ms, 0 ms |

**Real detail LCP breakdown** (`lcp-breakdown-insight`, all three runs): the element is hero slide 1, 31,244 B AVIF at High; FCP 1,201 ms; the other 6 images are Low. Observed (unthrottled) subparts: TTFB 6 to 9 ms, load delay 7 ms, load duration 6 to 7 ms, render delay 28 to 35 ms, so the simulated 1,726 ms is the throttled document plus that one photo. Real canton: the 44,121 B first card photo is the one High image, 42 Low, so the priority lever has nothing left there.

### After R2 (#41), F6, before R1 (history)

Final tree, one session on 2026-11-02, so one day seed: real 14:30 to 14:34 at load average 6 to 31, demo 14:40 to 14:43 at 6 to 7, and the D4 re-run of the real detail page at 14:54 at load 4 (Europe/Zurich). The figures from the first R2 session (02:51 to 03:00) are replaced.

**Demo ×1**

| Page | Perf | A11y | BP | SEO | LCP | CLS | TBT | FCP | LCP image |
|---|---|---|---|---|---|---|---|---|---|
| `/` | 100 | 100 | 100 | 100 | 1,352 ms | 0.000 | 0 ms | 902 ms | 4,334 B |
| `/hochzeitslocations` | 99 | 100 | 100 | 100 | 1,728 ms | 0.000 | 0 ms | 1,398 ms | 4,334 B |
| `?canton=zh&guests=80&setting[]=countryside` | 100 | 100 | 100 | 69 | 1,651 ms | 0.000 | 0 ms | 1,351 ms | 3,627 B |
| `/hochzeitslocations/zuerich` | 100 | 100 | 100 | 100 | 1,652 ms | 0.000 | 0 ms | 1,352 ms | 3,625 B |
| Detail, richest demo venue by `tests/Load/run.sh` (venue 46, 9 photos plus rooms) | 100 | 100 | 100 | 100 | 1,501 ms | 0.002 | 0 ms | 1,201 ms | 6,543 B |

**Real ×1**

| Page | Perf | A11y | BP | SEO | LCP | CLS | TBT | FCP | LCP image |
|---|---|---|---|---|---|---|---|---|---|
| `/` | 100 | 100 | 100 | 100 | 1,577 ms | 0.000 | 0 ms | 902 ms | 27,067 B |
| `/hochzeitslocations` | 99 | 100 | 100 | 100 | 1,879 ms | 0.000 | 12 ms | 1,399 ms | 24,998 B |
| `?canton=zh&guests=80&price[]=medium` | 99 | 100 | 100 | 69 | 1,953 ms | 0.000 | 26 ms | 1,395 ms | 27,985 B |
| `/hochzeitslocations/zuerich` | 98 | 100 | 100 | 100 | 2,329 ms | 0.000 | 13 ms | 1,411 ms | 44,121 B, venue 62 |
| Detail, richest real venue (venue 102, 13 photos), D4 re-run | 97 | 100 | 100 | 100 | 2,628 ms | 0.002 | 0 ms | 1,202 ms | 31,244 B |

Real detail's runs: 2,480, 1,726 and 2,629 ms in the session, and 1,728, 2,628 and 2,628 ms in the D4 re-run after `make perf-up`. F6 took the LCP image to be the run's highest-priority image request; that fallback was wrong, and R1 read the element from `lcp-breakdown-insight`: hero slide 1 (31,244 B) in every run, with the split coming from slide 2's priority (§8 Fix 3). The demo detail venue differs from the first session's because `run.sh` counts rooms with photos. The real filter differs from demo's, so its row is not like for like. In the filter state, `is-crawlable` is the only failing SEO audit on both datasets.

Transfer of the median run, in bytes, headers included:

| Page | Demo document | Demo images | Real document | Real images | CSS | JS | Fonts |
|---|---|---|---|---|---|---|---|
| Home | 6,550 | 19,022 | 6,766 | 110,990 | 14,883 | 4,787 | 20,450 |
| Listing | 57,930 | 121,633 | 56,749 | 1,348,481 | 14,883 | 14,318 | 20,450 |
| Three filters | 22,139 | 30,727 | 46,395 | 1,707,471 | 14,883 | 14,318 | 20,450 |
| Canton page | 25,714 | 110,508 | 56,926 | 1,454,290 | 14,883 | 14,318 | 20,450 |
| Detail | 11,817 | 39,220 | 11,318 | 310,710 | 14,883 | 11,104 | 20,450 |

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

### After R1 (#41)

Demo ×10 `MODE=all` (15:33 to 15:42) exit 0: uncached p95 34.3 to 61.3 ms (detail 55.6 ms against 120), cached 6.7 to 8.6 ms against 20, 0 `server_errors`, 0 `short_bodies`. Real ×1 `MODE=uncached` (`FILTERS='canton=zh&guests=80&event[]=corporate_event' CANTON=zuerich`) exit 0: p95 28.1 to 50.3 ms. R1 changed `src/Media/Twig/MediaExtension.php`, hence the k6 re-run (D15).

### After R2 (#41), F6 (history)

Final tree, 2026-11-02. The first session's runs (load average 7 to 17, two exits of 2) are replaced.

**Demo ×10, `make perf-run MODE=all`: exit 0.** It started at 14:44 at load average 3.9, after a 60 s wait for a load under 4, and ended at 3.4. 0 `server_errors`, 0 `short_bodies`, 0 failed requests, `cache_fresh` 99.83 % (3,010 of 3,015).

| Page | Uncached p95 | Budget | Cached p95 | Budget |
|---|---|---|---|---|
| home | 31.3 ms | 200 ms | 6.9 ms | 20 ms |
| listing | 52.2 ms | 200 ms | 9.0 ms | 20 ms |
| three filters | 42.7 ms | 200 ms | 8.6 ms | 20 ms |
| canton page | 53.7 ms | 200 ms | 8.4 ms | 20 ms |
| detail | 55.8 ms | 120 ms | 8.8 ms | 20 ms |

**Real ×1, `make perf-run MODE=uncached`: exit 0.** Load average 6.5 before, 4.5 after. 1,757 requests, 0 failed, 0 `server_errors`, 0 `short_bodies`.

| Page | p50 | p95 | p99 | Budget |
|---|---|---|---|---|
| home | 11.1 ms | 15.4 ms | 35.5 ms | 200 ms |
| listing | 25.5 ms | 37.9 ms | 72.9 ms | 200 ms |
| three filters | 35.5 ms | 46.7 ms | 63.5 ms | 200 ms |
| canton page | 27.3 ms | 45.7 ms | 67.2 ms | 200 ms |
| detail | 19.3 ms | 36.3 ms | 59.8 ms | 120 ms |

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

`make perf-check`, also in CI, exits 0 on the final tree. Every figure but the two real ×1 HTML rows is re-read on it. R1 read the JS rows (gzip level 9 per file, as `AssetBudgetTest` measures, checked against the previous figures on `HEAD`). The re-review read the HTML fixture rows with plan Step 3.3's command, `docker compose exec -T -e SEARCH_PERF_REPORT=1 php vendor/bin/phpunit --filter 'ListingHtmlBudgetTest|HomeHtmlBudgetTest'`, which prints them, and `app.css` by `AssetBudgetTest`'s method (source map stripped, `gzencode` level 9). The two real ×1 rows need the perf stack and were not re-read: they are from Step 5.1's tree, after §8's fixes 1 and 2 and before the move onto the portal, F4 and R1. The shipped CSS transfer is 14,883 B in R1's Lighthouse runs, which came before R1's removal of about 30 B of no-effect CSS in `_similar-venues.scss`. The test's HTML fixture cannot see real markup (§9), so the real rows are measured on the perf stack and are not part of the check.

| Budget | Figure | Limit |
|---|---|---|
| Listing HTML, 48 cards × 8 photos, full overlay | 52,169 B gzip (777,236 raw) | 61,440 |
| Canton page HTML, same test | 52,285 B gzip (778,151 raw) | 61,440 |
| Home HTML, same test | 6,372 B gzip (38,788 raw) | 61,440 |
| Listing HTML, real ×1 (Step 5.1's tree, not re-read) | 54,144 B gzip | 61,440 |
| Canton page HTML, real ×1 (Step 5.1's tree, not re-read) | 54,401 B gzip | 61,440 |
| `app.css`, as the test measures it | 13,900 B gzip | 25,600 |
| JS per page, base's scripts plus the page's (#47) | home 5,115 · listing 12,943 · detail 9,017 · Merkliste 6,153 B gzip | 15,360 each |
| `overlay.js + live-count.js + region-filter.js` | 6,132 B gzip | 6,144 |
| `carousel.js` | 1,038 B gzip | 2,048 |
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

The same photos measured 1,879, 1,953 and 2,329 ms in F6's re-measure and 1,878, 1,954 and 2,328 ms in R1's (§4); 1,884, 1,957 and 2,331 ms was the first R2 session, before the portal. Since fix 1 the 44 KB page has ranged from 2,327 to 2,406 ms across four sessions, and it missed in all of them.

| Candidate | Result |
|---|---|
| LCP image, second card's eager photo | **Fix 1.** It was the page's one Medium image, started with the LCP image, and its size followed the miss. It now prints `fetchpriority="low"` and stays eager. Listing 2,104 to 2,029 ms, three filters 2,703 to 1,955 ms, canton page 2,405 to 2,328 ms. The gain is mostly how the simulator counts; the field gain is unmeasured |
| Listing and canton HTML | **Fix 2.** Card slides 2–8 print `alt=""`, because their links are `aria-hidden` with `tabindex="-1"` and no reader meets them. Real listing 73,084 to 54,144 B gzip, canton page 71,782 to 54,401 B. It costs the listing's image search for those slides; the detail gallery keeps the alts. Accessibility stays 100 |
| Canton page LCP on real data | No code fix (ruling -34, 2026-11-02): one venue's 44 KB photo, venue 62; the re-run on another day's seed goes to #20 Step 9.3. The file cannot shrink by code, as there is no variant between 360 and 640 without regenerating 2,141 photos. R1: 2,328 ms; the photo is the page's only non-Low image, so no priority lever is left |
| Detail page LCP on real data | **Fix 3 (R1).** Hero slides 2+ print `fetchpriority="low"` and stay lazy (`venue_picture(…, lowPriority=true)`). Slide 2's box reaches 16 px into the phone viewport; Chrome rated its 105 KB AVIF High in every slow run and Low in every fast one, and Lantern ends LCP at the last non-Low image. 2,628 ms (F6, D4 re-run) to 1,726 ms, runs 1,726/1,727/1,726; demo 1,501 to 1,504 ms. The earlier reading "the split is the LCP photo's simulated load" was wrong: the LCP request was the same 31,244 B slide 1 in every run |
| AVIF quality | Not changed. Closed by the owner (2026-11-02): the quality stays |
| Fonts | None needed: the LCP element is an image on all five pages |
| `sizes` | None needed: drawn 332 px, DPR 1.75, 581 px wanted, 640w picked |
| Critical CSS | None needed: the CSS is not what misses |
| Query tuning | None needed: k6 is within budget on demo ×10 at load under 4 and on real ×1 (§5) |
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
- **`var/share/<env>` must be writable by the web user.** On the first run the store directory could not be created, and HttpCache answered 500 on every URL, `/health` included. `make perf-up` and `make perf-seed` now hand `var` to `www-data`. F15 runs php-fpm as the `deploy` user that owns `shared/var`, so the VPS has the same answer.
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
FILTERS='canton=zh&guests=80&event[]=corporate_event' CANTON=zuerich make perf-run MODE=uncached > var/perf/real-k6.log 2>&1
```

Run all five real pages in one session, because the day seed moves the first card at midnight Zurich. k6 budgets stay on demo ×10; real ×1 is recorded beside them, and a k6 miss counts only at load average under 4. `make perf-up` can leave nginx stopped under load: its `restart php nginx` races, and nginx exits on `host not found in upstream "php"`. Run it again.

The lane must be freshly seeded (`make db-fresh && make seed`), because `perf-seed` copies its database. Results land in `var/perf/` and `var/lighthouse/`. To test a clear under load, run `app:http-cache:clear` in a loop in the perf `php` container as `www-data` during `make perf-run MODE=all`. Expect k6 to exit 2 then, from the cached p95 thresholds and from `server_errors` when a clear hits a body read (§5). `make perf-up` and `make perf-seed` empty the store, so a re-run never serves the previous code's or dataset's pages; `make perf-lighthouse` warms with Lighthouse's `Host`, so every run is a hit.
