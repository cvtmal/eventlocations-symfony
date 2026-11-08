// k6 server-time test for PERF-1 (S11, #19; docs/performance-baseline.md) on the five measured pages.
//
//   home      /
//   listing   /eventlokale
//   filtered  /eventlokale?<FILTERS>      three filters
//   canton    /eventlokale/<CANTON>       a canton landing page
//   detail    /eventlokal/<DETAIL_SLUG>   the richest venue (run.sh picks it)
//
// MODE=uncached: per page a 10 s warm-up, then 60 s at 5 requests/s, pages one after the other. Each
// request carries k6=<run>-<iteration>, a new HttpCache key that SearchCriteria drops and the home page
// never reads, so every request is a backend render. MODE=all adds, after them, 30 s at 20 requests/s per page on the fixed
// URL (served by HttpCache once #19's Phase 3 is in).
// Thresholds (S11): uncached p95 < 200 ms for home and the listing pages and < 120 ms for the detail; cached
// p95 < 20 ms with X-Symfony-Cache "fresh"; checks (status 200) > 99 %. server_errors and short_bodies
// must stay 0: `checks` alone hides a few 500s in thousands of requests (#19's review, F-M3), and a
// status says nothing about a truncated body.
//
// Run through `make perf-run MODE=uncached|all` (tests/Load/run.sh).
import { check } from 'k6';
import exec from 'k6/execution';
import http from 'k6/http';
import { Counter, Rate } from 'k6/metrics';

const BASE = __ENV.BASE || 'http://nginx';
const MODE = __ENV.MODE || 'uncached';
const RUN = __ENV.RUN || String(Date.now());
// building[]=castle matches nothing on demo ×1 (/_search/count 0), so the default is the first setting
// with a result: countryside (1 venue on ×1 after tests/Load/live-canton.sql, 10 on ×10).
const FILTERS = __ENV.FILTERS || 'canton=zh&guests=80&event[]=workshop';
const CANTON = __ENV.CANTON || 'zuerich';
const DETAIL_SLUG = __ENV.DETAIL_SLUG;
if (!DETAIL_SLUG) {
    throw new Error('DETAIL_SLUG is required (tests/Load/run.sh sets it)');
}
if ('uncached' !== MODE && 'all' !== MODE) {
    throw new Error(`MODE must be uncached or all, got "${MODE}"`);
}

const PAGES = {
    home: { path: '/', p95: 200 },
    listing: { path: '/eventlokale', p95: 200 },
    filtered: { path: `/eventlokale?${FILTERS}`, p95: 200 },
    canton: { path: `/eventlokale/${CANTON}`, p95: 200 },
    detail: { path: `/eventlokal/${DETAIL_SLUG}`, p95: 120 },
};
const WARMUP_S = 10;
const UNCACHED_S = 60;
const UNCACHED_RATE = 5;
const CACHED_S = 30;
const CACHED_RATE = 20;
const GAP_S = 2;

const cacheFresh = new Rate('cache_fresh');
const serverErrors = new Counter('server_errors');
const shortBodies = new Counter('short_bodies');

function arrival(fn, page, rate, seconds, startSeconds, phase, kind) {
    return {
        executor: 'constant-arrival-rate',
        exec: fn,
        rate,
        timeUnit: '1s',
        duration: `${seconds}s`,
        startTime: `${startSeconds}s`,
        preAllocatedVUs: rate * 2,
        maxVUs: rate * 10,
        env: { PAGE: page },
        tags: { page, phase, kind },
    };
}

const scenarios = {};
const thresholds = {
    'checks{phase:measure}': ['rate>0.99'],
    server_errors: ['count==0'],
    short_bodies: ['count==0'],
};
let start = 0;
for (const [page, { p95 }] of Object.entries(PAGES)) {
    scenarios[`warm_${page}`] = arrival('uncached', page, UNCACHED_RATE, WARMUP_S, start, 'warmup', 'uncached');
    start += WARMUP_S;
    scenarios[`uncached_${page}`] = arrival('uncached', page, UNCACHED_RATE, UNCACHED_S, start, 'measure', 'uncached');
    start += UNCACHED_S + GAP_S;
    thresholds[`http_req_duration{scenario:uncached_${page}}`] = [`p(95)<${p95}`];
    // These bounds never fail; they only make the summary report the sub-metrics per page.
    thresholds[`http_req_failed{scenario:uncached_${page}}`] = ['rate<=1'];
    thresholds[`http_reqs{scenario:uncached_${page}}`] = ['count>=0'];
}
if ('all' === MODE) {
    for (const page of Object.keys(PAGES)) {
        // One request first, so the store holds the page before the measured scenario starts.
        scenarios[`prime_${page}`] = arrival('cached', page, 1, 2, start, 'warmup', 'cached');
        start += 2;
        scenarios[`cached_${page}`] = arrival('cached', page, CACHED_RATE, CACHED_S, start, 'measure', 'cached');
        start += CACHED_S + GAP_S;
        thresholds[`http_req_duration{scenario:cached_${page}}`] = ['p(95)<20'];
        thresholds[`http_req_failed{scenario:cached_${page}}`] = ['rate<=1'];
        thresholds[`http_reqs{scenario:cached_${page}}`] = ['count>=0'];
    }
    thresholds['cache_fresh{phase:measure}'] = ['rate>0.99'];
}

export const options = {
    scenarios,
    thresholds,
    summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
    discardResponseBodies: true,
};

function get(url) {
    // Bodies are discarded by default; a text body here is what lets a short one be seen.
    const response = http.get(url, { tags: { name: __ENV.PAGE }, responseType: 'text' });
    check(response, { 'status 200': (r) => 200 === r.status });
    if (500 <= response.status) {
        serverErrors.add(1);
    } else if (200 === response.status && !response.body.trimEnd().endsWith('</html>')) {
        shortBodies.add(1);
    }

    return response;
}

export function uncached() {
    const { path } = PAGES[__ENV.PAGE];
    const separator = path.includes('?') ? '&' : '?';
    get(`${BASE}${path}${separator}k6=${RUN}-${exec.scenario.name}-${exec.scenario.iterationInTest}`);
}

export function cached() {
    const response = get(`${BASE}${PAGES[__ENV.PAGE].path}`);
    cacheFresh.add((response.headers['X-Symfony-Cache'] || '').includes('fresh'));
}
