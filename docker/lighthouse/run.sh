#!/usr/bin/env bash
# Usage: lighthouse-run <url> <name>. Writes /reports/<name>.report.{json,html} (mounted from var/lighthouse/).
set -euo pipefail

if [[ $# -ne 2 ]]; then
    echo "Usage: lighthouse-run <url> <name>" >&2
    exit 64
fi

lighthouse "$1" \
    --output=json --output=html \
    --output-path="/reports/$2" \
    --chrome-flags="--headless=new --no-sandbox --disable-gpu" \
    --quiet

node /usr/local/lib/print-scores.mjs "/reports/$2.report.json"
