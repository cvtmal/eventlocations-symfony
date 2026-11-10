#!/bin/sh
# The review demo's start from a clean clone (review-demo-finish; docs/operations.md «Review demo for the operator»).
# Runs on the host, like nginx-check.sh: needs git, jq, python3 and Docker (for `docker compose config` only).
#
# 1. compose.review.yaml mounts the checkout read-only, so Docker cannot create a mount point inside it: every
#    mount that lands inside a read-only bind of the checkout needs its path tracked in git (what a clean clone
#    has) or made by review-up's `mkdir -p` line. An untracked folder left behind by the perf stack hid this once.
# 2. `tunnel.sh check`, run by review-up before any build, names a port another process listens on and the
#    override, in one line, and lets free ports through.
set -eu

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
work="$(mktemp -d)"
listener=""
listener2=""

cleanup() {
    [ -z "$listener" ] || kill "$listener" 2>/dev/null || true
    [ -z "$listener2" ] || kill "$listener2" 2>/dev/null || true
    rm -rf "$work"
}
trap cleanup EXIT

ok() { printf '%s\n' "ok - $1"; }
fail() {
    printf '%s\n' "not ok - $1" >&2
    exit 1
}

# —— 1. Mount points inside the read-only checkout ——
REVIEW_APP_SECRET=check-only docker compose --project-directory "$ROOT" -p deine-eventlocation-review \
    -f "$ROOT/compose.yaml" -f "$ROOT/compose.perf.yaml" -f "$ROOT/compose.review.yaml" config --format json > "$work/config.json"

# Each mount whose target lies under a read-only bind of the same service, as a path relative to the checkout.
jq -r --arg root "$ROOT" '
    .services[] | (.volumes // []) as $v | $v[] as $m
    | ([$v[] | select(.type == "bind" and .read_only == true and (.target as $t | $m.target | startswith($t + "/")))]
        | max_by(.target | length)) as $b
    | select($b != null)
    | ($b.source + ($m.target | ltrimstr($b.target)))
    | select(startswith($root + "/")) | ltrimstr($root + "/")
' "$work/config.json" | sort -u > "$work/mountpoints"

[ -s "$work/mountpoints" ] || fail "found no mount point inside the read-only checkout (has compose.review.yaml changed shape?)"

made="$(sed -n '/^review-up:/,/^review-down:/p' "$ROOT/Makefile" | sed -n 's/^[[:space:]]*mkdir -p //p')"
[ -n "$made" ] || fail "review-up has no mkdir -p line"

while IFS= read -r path; do
    if [ -n "$(git -C "$ROOT" ls-files -- "$path")" ]; then
        ok "mount point $path is tracked"
    elif printf '%s\n' "$made" | tr ' ' '\n' | grep -qx "$path"; then
        ok "mount point $path is made by review-up"
    else
        fail "mount point $path is neither tracked in git nor made by review-up's mkdir -p: Docker cannot create it in the read-only checkout"
    fi
done < "$work/mountpoints"

grep -qx 'config/packages/prod' "$work/mountpoints" || fail "the review config's mount point config/packages/prod was not checked"
[ -n "$(git -C "$ROOT" ls-files -- config/packages/prod)" ] || fail "config/packages/prod is not tracked (config/packages/prod/.gitkeep)"
ok "config/packages/prod exists in a clean clone"

# —— 2. Busy ports ——
mkdir -p "$work/bin"
printf '#!/bin/sh\nexit 0\n' > "$work/bin/cloudflared"
chmod +x "$work/bin/cloudflared"

free_port() { python3 -c 'import socket; s = socket.socket(); s.bind(("127.0.0.1", 0)); print(s.getsockname()[1]); s.close()'; }

python3 -c '
import socket, sys, time
s = socket.socket()
s.bind(("127.0.0.1", 0))
s.listen()
print(s.getsockname()[1], flush=True)
time.sleep(120)
' > "$work/busy" &
listener=$!
i=0
until [ -s "$work/busy" ]; do
    i=$((i + 1))
    [ "$i" -lt 50 ] || fail "the throwaway listener did not start"
    sleep 0.1
done
busy="$(cat "$work/busy")"
free="$(free_port)"

check() { PATH="$work/bin:$PATH" sh "$ROOT/docker/review/tunnel.sh" check "$1" "$2" > "$work/out" 2>&1; }

if check "$busy" "$free"; then fail "check let the taken app port $busy through"; fi
[ "$(wc -l < "$work/out" | tr -d ' ')" = 1 ] || fail "the busy-port message is not one line: $(cat "$work/out")"
grep -q "port $busy is taken" "$work/out" || fail "the message does not name port $busy: $(cat "$work/out")"
grep -q "make review-up REVIEW_PORT=$((busy + 100)) REVIEW_MAIL_PORT=$free\$" "$work/out" \
    || fail "the message does not give the override: $(cat "$work/out")"
ok "a taken app port stops review-up with one line and the override"

if check "$free" "$busy"; then fail "check let the taken mail port $busy through"; fi
grep -q "REVIEW_PORT=$free REVIEW_MAIL_PORT=$((busy + 100))\$" "$work/out" \
    || fail "the message does not give the mail override: $(cat "$work/out")"
ok "a taken mail port stops review-up with one line and the override"

# Both taken: one line naming both.
python3 -c '
import socket, time
s = socket.socket()
s.bind(("127.0.0.1", 0))
s.listen()
print(s.getsockname()[1], flush=True)
time.sleep(120)
' > "$work/busy2" &
listener2=$!
i=0
until [ -s "$work/busy2" ]; do
    i=$((i + 1))
    [ "$i" -lt 50 ] || fail "the second throwaway listener did not start"
    sleep 0.1
done
busy2="$(cat "$work/busy2")"
if check "$busy" "$busy2"; then fail "check let two taken ports through"; fi
[ "$(wc -l < "$work/out" | tr -d ' ')" = 1 ] || fail "the two-port message is not one line: $(cat "$work/out")"
grep -q "ports $busy and $busy2 are taken" "$work/out" || fail "the message does not name both ports: $(cat "$work/out")"
kill "$listener2" 2>/dev/null || true
wait "$listener2" 2>/dev/null || true
listener2=""
ok "two taken ports are named in one line"

# The review stack's own edge on the port (make review-up again while it runs): a stub docker reports it.
mkdir -p "$work/own"
printf '#!/bin/sh\necho "127.0.0.1:%s->80/tcp"\n' "$busy" > "$work/own/docker"
chmod +x "$work/own/docker"
PATH="$work/own:$work/bin:$PATH" sh "$ROOT/docker/review/tunnel.sh" check "$busy" "$free" > "$work/out" 2>&1 \
    || fail "check refused the review stack's own port: $(cat "$work/out")"
ok "the review stack's own port passes"

kill "$listener" 2>/dev/null || true
wait "$listener" 2>/dev/null || true
listener=""
check "$busy" "$free" || fail "check refused free ports: $(cat "$work/out")"
ok "free ports pass"
