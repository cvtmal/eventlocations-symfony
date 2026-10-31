#!/usr/bin/env bash
# Runs the PERF-1 k6 scenarios (tests/Load/pages.js) against the perf stack (compose.perf.yaml) and
# collects the evidence in var/perf/: the k6 summary, docker stats samples every 5 s, and the top 10
# pg_stat_statements of the run.
# Usage: tests/Load/run.sh <uncached|all>. Normally called by `make perf-run MODE=…` after
# `make perf-seed TIMES=10`. Exits with k6's exit code: non-zero means a threshold failed.
# FILTERS=… and CANTON=… override the page definitions in pages.js.
set -euo pipefail

MODE="${1:?usage: tests/Load/run.sh <uncached|all>}"
case "$MODE" in uncached|all) ;; *) echo "MODE must be uncached or all, got \"$MODE\"" >&2; exit 64 ;; esac
OUT=var/perf
PERF=(docker compose -p hochzeitslocation-perf -f compose.yaml -f compose.perf.yaml)
PSQL=("${PERF[@]}" exec -T database psql -U app -d app -v ON_ERROR_STOP=1 -At)
TIMES="$(cat "$OUT/seed-times" 2>/dev/null || echo 1)"
TAG="$MODE-x$TIMES"

mkdir -p "$OUT"
: > "$OUT/stats-$TAG.log"

# The richest published venue (photos plus rooms) with a default-locale translation; clones have no
# rooms, so on ×10 it is the same venue as on ×1.
DETAIL_SLUG="$("${PSQL[@]}" -c "SELECT t.slug FROM venues v JOIN venue_translations t ON t.venue_id = v.id AND t.locale = 'de' WHERE v.status = 'published' ORDER BY (SELECT count(*) FROM venue_photos p WHERE p.venue_id = v.id) + (SELECT count(*) FROM rooms r WHERE r.venue_id = v.id) DESC, v.number LIMIT 1")"
test -n "$DETAIL_SLUG" || { echo 'No published venue in the perf database: run make perf-seed first.' >&2; exit 1; }
echo "DETAIL_SLUG=$DETAIL_SLUG (x$TIMES)"

# pg_stat_statements then covers only this run.
"${PSQL[@]}" -c 'SELECT pg_stat_statements_reset()' > /dev/null

# The perf containers share cores 0 and 1; k6 gets other cores so it doesn't compete with them.
(
    while true; do
        docker stats --no-stream --format "$(date +%H:%M:%S) {{.Name}} {{.CPUPerc}} {{.MemUsage}}" \
            hochzeitslocation-perf-php-1 hochzeitslocation-perf-nginx-1 hochzeitslocation-perf-database-1 >> "$OUT/stats-$TAG.log"
        sleep 5
    done
) &
SAMPLER=$!
trap 'kill "$SAMPLER" 2>/dev/null || true' EXIT

set +e
docker run --rm --network hochzeitslocation-perf_default --cpuset-cpus 2-7 \
    -v "$PWD/tests/Load:/scripts:ro" -v "$PWD/$OUT:/results" \
    -e MODE="$MODE" -e DETAIL_SLUG="$DETAIL_SLUG" -e RUN="$(date +%s)" \
    ${FILTERS:+-e FILTERS="$FILTERS"} ${CANTON:+-e CANTON="$CANTON"} \
    grafana/k6:2.3.0 run --summary-export "/results/k6-$TAG.json" \
    /scripts/pages.js | tee "$OUT/k6-$TAG.txt"
K6_EXIT=${PIPESTATUS[0]}
set -e

"${PERF[@]}" exec -T database psql -U app -d app -c "SELECT calls, round(total_exec_time) AS total_ms, round(mean_exec_time::numeric, 2) AS mean_ms, rows, left(regexp_replace(query, '\s+', ' ', 'g'), 160) AS query FROM pg_stat_statements ORDER BY total_exec_time DESC LIMIT 10" > "$OUT/pgss-$TAG.txt"

echo
echo "k6 summary:   $OUT/k6-$TAG.txt ($OUT/k6-$TAG.json)"
echo "docker stats: $OUT/stats-$TAG.log"
echo "top SQL:      $OUT/pgss-$TAG.txt"
exit "$K6_EXIT"
