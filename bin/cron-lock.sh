#!/bin/sh
# Sourced by bin/cron-midnight and bin/cron-cache-guard (#20, #19 re-review n2).
# Both empty the same HTTP cache store (var/share/prod/http_cache) and fire in the same minute at
# 00:05, and ClearHttpCacheCommand takes no lock. This serialises them with a mkdir lock, which is
# atomic on every filesystem and needs no flock binary.
#
#   cache_lock_acquire <tries>   0 = got it; 1 = still held after <tries> one-second waits
#   cache_lock_release
#
# A lock older than 10 minutes belongs to a dead run and is taken over. var/lock is shared between
# releases (bin/deploy), so a run in the old release and one in the new one still exclude each other.
LOCK_DIR="${CRON_LOCK_DIR:-var/lock/cache-clear.lock}"

cache_lock_acquire() {
    tries="$1"
    while :; do
        if mkdir "$LOCK_DIR" 2>/dev/null; then
            return 0
        fi
        # An old lock is a dead run's. find prints the path only when it is older than 10 minutes.
        if [ -n "$(find "$LOCK_DIR" -maxdepth 0 -mmin +10 2>/dev/null)" ]; then
            rmdir "$LOCK_DIR" 2>/dev/null || true
            continue
        fi
        tries=$((tries - 1))
        [ "$tries" -gt 0 ] || return 1
        sleep 1
    done
}

cache_lock_release() {
    rmdir "$LOCK_DIR" 2>/dev/null || true
}
