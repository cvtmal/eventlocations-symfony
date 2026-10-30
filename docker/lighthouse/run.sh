#!/usr/bin/env bash
# Usage: lighthouse-run <url> <name> [extra-headers-json]. Writes /reports/<name>.report.{json,html} (mounted from var/lighthouse/).
set -euo pipefail

if [[ $# -lt 2 || $# -gt 3 ]]; then
    echo "Usage: lighthouse-run <url> <name> [extra-headers-json]" >&2
    exit 64
fi

extra_headers=()
if [[ $# -eq 3 ]]; then
    extra_headers=(--extra-headers="$3")
fi

lighthouse "$1" \
    --output=json --output=html \
    --output-path="/reports/$2" \
    --chrome-flags="--headless=new --no-sandbox --disable-gpu" \
    "${extra_headers[@]}" \
    --quiet

node /usr/local/lib/print-scores.mjs "/reports/$2.report.json"
