#!/bin/sh
# Runs the real deploy, cron and import scripts against a throwaway tree (#20, Step 4.7), through
# `make deploy-test`, inside the php container. Nothing here needs an account or a network, apart
# from rsync (below). PHP is a stub that logs "<dir> <console command>", so the order of the calls
# is what is tested; rsync is the real one, because what survives a deploy is its exclude list.
#
# Every case prints "ok - …". The first failure stops the run, prints the log, and exits 1.
set -eu

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SHA=0123456789abcdef0123456789abcdef01234567
OTHER_SHA=fedcba9876543210fedcba9876543210fedcba98
PORT=18097

# The image has no rsync and the hosting has; bin/deploy cannot run without it. Added here, not in
# the Dockerfile, because nothing else needs it and an image rebuild is every lane's cost.
command -v rsync >/dev/null 2>&1 || apk add --no-cache --quiet rsync

work="$ROOT/var/deploy-test/$$-$(od -An -N4 -tx4 /dev/urandom | tr -d ' ')"
HOME="$work/home"
DEPLOY_APP="$HOME/www/hochzeitslocation.ch"
DEPLOY_WORK="$HOME/deploy/hochzeitslocation"
DEPLOY_PHP="$work/stub-php"
STUB_LOG="$work/calls"
export HOME DEPLOY_APP DEPLOY_WORK DEPLOY_PHP STUB_LOG
APP="$DEPLOY_APP"
STAGE="$DEPLOY_WORK/stage"
server=""

cleanup() {
    [ -z "$server" ] || kill "$server" 2>/dev/null || true
    rm -rf "$work"
    rmdir "$ROOT/var/deploy-test" 2>/dev/null || true
}
trap cleanup EXIT

ok() { printf '%s\n' "ok - $1"; }
fail() {
    printf '%s\n' "not ok - $1" >&2
    printf '%s\n' "${out:-}" | sed 's/^/  output: /' >&2
    [ ! -f "$STUB_LOG" ] || sed 's/^/  call: /' "$STUB_LOG" >&2
    exit 1
}

# run <command...>: keeps going on a failure, leaves the exit code in $rc and the output in $out.
run() {
    rc=0
    out="$("$@" 2>&1)" || rc=$?
}

clear_log() { : > "$STUB_LOG"; }
calls() { sed "s#$STAGE#stage#; s#$APP#app#; s#$work/checkout#checkout#g" "$STUB_LOG"; }

expect_calls() { # expect_calls <case> <one call per line>
    [ "$(calls)" = "$2" ] || fail "$1: other calls than expected"
    ok "$1"
}
expect_no_calls() { # expect_no_calls <case>
    [ ! -s "$STUB_LOG" ] || fail "$1: something was called"
    ok "$1"
}
expect_exit() { # expect_exit <case> <code>
    [ "$rc" = "$2" ] || fail "$1: exit $rc, wanted $2"
    ok "$1"
}
expect_failure() { # expect_failure <case>
    [ "$rc" != 0 ] || fail "$1: exit 0, wanted a failure"
    ok "$1"
}
expect_output() { # expect_output <case> <text>
    case "$out" in
        *"$2"*) ok "$1" ;;
        *) fail "$1: the output lacks '$2'" ;;
    esac
}
expect_no_output() { # expect_no_output <case>
    [ -z "$out" ] || fail "$1: it printed something"
    ok "$1"
}
expect_file() { # expect_file <case> <path> <content>
    [ -f "$2" ] || fail "$1: $2 is missing"
    [ "$(cat "$2")" = "$3" ] || fail "$1: other content in $2"
    ok "$1"
}
expect_gone() { # expect_gone <case> <path>
    [ ! -e "$2" ] || fail "$1: $2 is still there"
    ok "$1"
}
tree_state() {
    (cd "$APP" && find . -type f -exec cksum {} + | sort && find . -type l | sort && find . -type d | sort)
}
remember_tree() { tree_before="$(tree_state)"; }
expect_tree_unchanged() { # expect_tree_unchanged <case>
    [ "$tree_before" = "$(tree_state)" ] || fail "$1: the live tree changed"
    ok "$1"
}
expect_clean_gate() { # expect_clean_gate <case>
    [ ! -e "$DEPLOY_WORK/.lock" ] || fail "$1: the lock is left behind"
    [ ! -e "$STAGE" ] || fail "$1: the stage is left behind"
    ok "$1"
}

gate() { # gate <file for stdin> <ssh command>
    SSH_ORIGINAL_COMMAND="$2" sh "$ROOT/bin/deploy-gate" < "$1"
}

write_stub_php() {
    cat > "$DEPLOY_PHP" <<'STUB'
#!/bin/sh
# Stands in for PHP. Knobs: STUB_FAIL=<command> fails that command, STUB_PRINT=<command> makes it
# print a line, STUB_NO_GUARD and STUB_HTTP_CACHE change `list`, and STUB_HOLD=<file> makes it wait
# for that file after touching STUB_STARTED. STUB_BROKEN_ENV makes the console die before it runs a
# command, with a secret in its stderr, as a Dotenv FormatException can.
#
# Like the real kernel with debug off, it boots on var/cache/prod/container without looking at the
# tree: when that file names a path the tree no longer has, every command dies, cache:clear too.
if [ "$1" = -r ]; then
    echo 8.5.0-stub
    exit 0
fi
shift
while :; do
    case "$1" in
        --no-interaction | --quiet) shift ;;
        *) break ;;
    esac
done
command="$1"
printf '%s %s\n' "$(pwd)" "$*" >> "$STUB_LOG"
if [ -n "${STUB_BROKEN_ENV:-}" ]; then
    echo 'stub: syntax error in .env.local: APP_SECRET=stub-secret-excerpt' >&2
    exit 1
fi
if [ -f var/cache/prod/container ] && [ ! -e "$(cat var/cache/prod/container)" ]; then
    echo "stub: class in $(cat var/cache/prod/container) not found" >&2
    exit 255
fi
[ -z "${STUB_STARTED:-}" ] || : > "$STUB_STARTED"
if [ -n "${STUB_HOLD:-}" ]; then
    until [ -e "$STUB_HOLD" ]; do sleep 0.05; done
fi
if [ "$command" = "${STUB_FAIL:-}" ]; then
    echo "stub: $command failed" >&2
    exit 1
fi
[ "$command" != "${STUB_PRINT:-}" ] || echo "stub: $command says something"
if [ "$command" = cache:warmup ]; then
    mkdir -p var/cache/prod
    echo bin/console > var/cache/prod/container
fi
if [ "$command" = list ]; then
    [ -n "${STUB_NO_GUARD:-}" ] || echo 'app:prod:check-env   Refuse an unsafe environment'
    [ -z "${STUB_HTTP_CACHE:-}" ] || echo 'app:http-cache:clear   Empty the HTTP cache'
    echo 'app:geo:import-towns   Import the towns'
fi
exit 0
STUB
    chmod +x "$DEPLOY_PHP"
}

# tarball <dir> <file>
pack() { tar -C "$1" -czf "$2" .; }

build_release() { # build_release <dir>: what bin/build-release ships, as far as a deploy can tell
    mkdir -p "$1/bin" "$1/vendor" "$1/public/assets" "$1/public/admin-build/.vite" "$1/src/var" "$1/public/images"
    for script in deploy cron-lock.sh cron-midnight cron-cache-guard cron-daily; do
        cp "$ROOT/bin/$script" "$1/bin/$script"
    done
    echo console > "$1/bin/console"
    echo runtime > "$1/vendor/autoload_runtime.php"
    echo index > "$1/public/index.php"
    echo '{}' > "$1/public/assets/manifest.json"
    echo '{}' > "$1/public/admin-build/.vite/manifest.json"
    echo new > "$1/new-in-this-release"
    echo new > "$1/src/var/new"
    echo new > "$1/public/images/new"
}

live_tree() {
    mkdir -p "$APP/var/sessions/prod" "$APP/var/media/content" "$APP/var/share/prod/http_cache" "$APP/public/media/1" \
        "$APP/public/.well-known/acme-challenge" "$APP/src/var" "$APP/old-dir"
    echo secrets > "$APP/.env.local"
    chmod 600 "$APP/.env.local"
    echo session > "$APP/var/sessions/prod/sess_a"
    echo original > "$APP/var/media/content/photo.jpg"
    echo page > "$APP/var/share/prod/http_cache/page"
    echo variant > "$APP/public/media/1/a-360.jpg"
    echo token > "$APP/public/.well-known/acme-challenge/t"
    echo stale > "$APP/old-dir/stale"
    echo stale > "$APP/stale-file"
    # Anchored excludes: a same-named path deeper down is no exception.
    echo stale > "$APP/src/var/stale"
}

gate_refusals() {
    remember_tree
    clear_log
    for command in "deploy abc" "rm -rf /" "" "rollback" "rollback $SHA" "deploy" "deploy " "deploy ${SHA}0" \
        "deploy ${SHA} ; id" "deploy $(printf '%s' "$SHA" | tr 'a-f' 'A-F')" "purge now" "check; id"; do
        run gate /dev/null "$command"
        [ "$rc" = 2 ] || fail "the gate let '$command' through: exit $rc"
    done
    ok "the gate refuses everything but deploy, purge and check, with exit 2"
    expect_no_calls "a refused command runs nothing"
    expect_tree_unchanged "a refused command changes nothing"
}

deploy_ok() {
    clear_log
    run gate "$work/release.tgz" "deploy $SHA"
    expect_exit "deploy exits 0" 0
    expect_output "deploy prints the sha" "DEPLOYED_SHA=$SHA"
    expect_output "a build without #19 skips the HTTP cache with a note" "no app:http-cache:clear in this build"
    expect_calls "the calls come in order, stage first, then the app" "stage list --raw
stage app:prod:check-env
stage doctrine:migrations:migrate --allow-no-migration
app cache:warmup
app app:geo:import-towns
app list --raw"

    expect_file ".env.local survives" "$APP/.env.local" secrets
    { [ -f "$APP/.env.local" ] && [ ! -L "$APP/.env.local" ]; } || fail ".env.local became a link"
    [ "$(stat -c %a "$APP/.env.local")" = 600 ] || fail ".env.local lost its mode"
    ok ".env.local stays a private file"
    expect_file "sessions survive" "$APP/var/sessions/prod/sess_a" session
    expect_file "originals survive" "$APP/var/media/content/photo.jpg" original
    expect_file "the HTTP cache store survives the sync" "$APP/var/share/prod/http_cache/page" page
    expect_file "public/media survives" "$APP/public/media/1/a-360.jpg" variant
    expect_file "public/.well-known survives" "$APP/public/.well-known/acme-challenge/t" token
    expect_gone "a stale file is gone" "$APP/stale-file"
    expect_gone "a stale directory is gone" "$APP/old-dir"
    expect_gone "an exclude does not reach a same-named deeper path" "$APP/src/var/stale"
    expect_file "new files arrive" "$APP/new-in-this-release" new
    expect_file "the sha is in .env.prod.local" "$APP/.env.prod.local" "SENTRY_RELEASE=$SHA"
    expect_clean_gate "a deploy leaves no lock and no stage"
}

# rsync -t skips a file of the same size and second, and this run is faster than any real deploy.
age_the_sha_file() { touch -t 202001010000 "$APP/.env.prod.local"; }

deploy_with_http_cache() {
    clear_log
    age_the_sha_file
    STUB_HTTP_CACHE=1
    export STUB_HTTP_CACHE
    run gate "$work/release.tgz" "deploy $OTHER_SHA"
    unset STUB_HTTP_CACHE
    expect_exit "a second deploy exits 0" 0
    expect_calls "a build with the command empties the HTTP cache last" "stage list --raw
stage app:prod:check-env
stage doctrine:migrations:migrate --allow-no-migration
app cache:warmup
app app:geo:import-towns
app list --raw
app app:http-cache:clear"
    expect_file "the new sha replaces the old one" "$APP/.env.prod.local" "SENTRY_RELEASE=$OTHER_SHA"
}

# The old container names a bundle the new release no longer ships. rsync deletes the bundle, and the
# first console command in the app would die on that container (F-M2).
deploy_that_drops_a_bundle() {
    mkdir -p "$APP/vendor/dropped-bundle" "$APP/var/cache/prod"
    echo bundle > "$APP/vendor/dropped-bundle/Bundle.php"
    echo vendor/dropped-bundle/Bundle.php > "$APP/var/cache/prod/container"
    mkdir "$APP/var/cache/prod.old"
    echo left > "$APP/var/cache/prod.old/left-by-a-dead-run"
    age_the_sha_file
    clear_log
    run gate "$work/release.tgz" "deploy $SHA"
    expect_exit "a deploy that drops a bundle exits 0" 0
    expect_calls "the old container never boots the new code" "stage list --raw
stage app:prod:check-env
stage doctrine:migrations:migrate --allow-no-migration
app cache:warmup
app app:geo:import-towns
app list --raw"
    expect_gone "the bundle is gone" "$APP/vendor/dropped-bundle"
    expect_gone "a dead run's old cache is gone" "$APP/var/cache/prod.old"
    expect_file "the app runs on a new container" "$APP/var/cache/prod/container" bin/console
}

deploy_failures() {
    remember_tree

    clear_log
    STUB_FAIL=app:prod:check-env
    export STUB_FAIL
    run gate "$work/release.tgz" "deploy $SHA"
    expect_failure "a failing env guard fails the deploy"
    expect_output "the guard's failure says nothing changed" "the env guard refused, nothing was changed"
    expect_tree_unchanged "a failing env guard leaves the live tree"
    expect_calls "a failing env guard runs no migration" "stage list --raw
stage app:prod:check-env"
    expect_clean_gate "a failing env guard cleans up"

    clear_log
    STUB_FAIL=doctrine:migrations:migrate
    run gate "$work/release.tgz" "deploy $SHA"
    expect_failure "a failing migration fails the deploy"
    expect_output "the migration's failure says the app is unchanged" "the app is unchanged"
    expect_tree_unchanged "a failing migration leaves the live tree"
    unset STUB_FAIL

    clear_log
    STUB_NO_GUARD=1
    export STUB_NO_GUARD
    run gate "$work/release.tgz" "deploy $SHA"
    unset STUB_NO_GUARD
    expect_failure "a release without the env guard is refused"
    expect_output "the refusal names the guard" "app:prod:check-env is missing"
    expect_tree_unchanged "a release without the env guard leaves the live tree"

    clear_log
    STUB_BROKEN_ENV=1
    export STUB_BROKEN_ENV
    run gate "$work/release.tgz" "deploy $SHA"
    unset STUB_BROKEN_ENV
    expect_failure "a console that does not boot fails the deploy"
    expect_output "the failure says the console did not boot" "does not boot"
    case "$out" in
        *"is missing in this release"*) fail "a broken boot reads as a release without the guard" ;;
        *stub-secret-excerpt*) fail "the console's stderr reached the output" ;;
        *) ok "a broken boot is named as such, and its stderr stays out" ;;
    esac
    expect_tree_unchanged "a console that does not boot leaves the live tree"

    for file in bin/console vendor/autoload_runtime.php public/index.php public/assets/manifest.json public/admin-build/.vite/manifest.json; do
        rm -rf "$work/broken"
        cp -R "$work/release" "$work/broken"
        rm "$work/broken/$file"
        pack "$work/broken" "$work/broken.tgz"
        clear_log
        run gate "$work/broken.tgz" "deploy $SHA"
        expect_failure "a tarball without $file is refused"
        expect_output "the refusal names $file" "the tarball lacks $file"
        expect_no_calls "a tarball without $file runs nothing"
    done
    expect_tree_unchanged "a broken tarball leaves the live tree"

    printf 'this is no tarball' > "$work/garbage"
    clear_log
    run gate "$work/garbage" "deploy $SHA"
    expect_failure "garbage on stdin fails the deploy"
    expect_no_calls "garbage on stdin runs nothing"
    expect_tree_unchanged "garbage on stdin leaves the live tree"
    expect_clean_gate "garbage on stdin cleans up"

    mkdir "$DEPLOY_WORK/.lock"
    clear_log
    run gate "$work/release.tgz" "deploy $SHA"
    expect_exit "a held lock refuses a second deploy" 2
    expect_output "the refusal says why" "another deploy is running"
    expect_no_calls "a held lock runs nothing"
    expect_tree_unchanged "a held lock leaves the live tree"
    [ -d "$DEPLOY_WORK/.lock" ] || fail "the refused deploy took the other run's lock away"
    ok "the refused deploy keeps the other run's lock"
    rmdir "$DEPLOY_WORK/.lock"

    mkdir "$work/no-rsync"
    for tool in sh mkdir rm head tar gzip ln dirname grep sed printf cat pwd; do
        path="$(command -v "$tool" || true)"
        case "$path" in
            /*) ln -s "$path" "$work/no-rsync/$tool" ;;
            *) ;;
        esac
    done
    clear_log
    SSH_ORIGINAL_COMMAND="deploy $SHA" run env PATH="$work/no-rsync" "$work/no-rsync/sh" "$ROOT/bin/deploy-gate" < "$work/release.tgz"
    expect_failure "no rsync fails the deploy"
    expect_output "the refusal names rsync" "rsync is not installed"
    expect_no_calls "no rsync runs no migration"
    expect_tree_unchanged "no rsync leaves the live tree"
    expect_clean_gate "no rsync cleans up"

    mv "$APP/.env.local" "$work/env.local.saved"
    clear_log
    run gate "$work/release.tgz" "deploy $SHA"
    expect_failure "a server without .env.local is refused"
    expect_output "the refusal names the file" ".env.local is missing"
    expect_no_calls "a server without .env.local runs nothing"
    mv "$work/env.local.saved" "$APP/.env.local"

    run sh "$APP/bin/deploy" install "$SHA"
    expect_failure "install refuses to run from the app itself"
    expect_output "the refusal says where it must run" "must run from the unpacked tarball"

    run sh "$work/release/bin/deploy" install abc
    expect_failure "install refuses a short sha on its own too"
}

deploy_failures_after_the_sync() {
    age_the_sha_file
    clear_log
    SSH_ORIGINAL_COMMAND="deploy $SHA" STUB_RSYNC_EXIT=23 run env PATH="$work/tools:$PATH" sh "$ROOT/bin/deploy-gate" < "$work/release.tgz"
    expect_exit "a failing rsync fails the deploy with its own message" 1
    expect_output "the failure says the tree may be mixed" "the live tree may be a mix of old and new files"
    expect_output "the failure says what to do" "then deploy again"
    expect_clean_gate "a failing rsync cleans up"
    if grep -q '^/.* cache:warmup' "$STUB_LOG"; then
        fail "the deploy went on after a failing rsync"
    fi
    ok "a failing rsync stops the deploy"

    age_the_sha_file
    STUB_FAIL=cache:warmup
    export STUB_FAIL
    clear_log
    run gate "$work/release.tgz" "deploy $SHA"
    expect_failure "a failing cache:warmup fails the deploy"
    expect_output "the failure says the files are live" "files are live but cache:warmup failed"
    expect_output "the failure names the command that recovers" "rm -rf var/cache/prod var/cache/prod.old && $DEPLOY_PHP bin/console cache:warmup"
    STUB_FAIL=app:geo:import-towns
    clear_log
    run gate "$work/release.tgz" "deploy $SHA"
    expect_failure "a failing towns import fails the deploy"
    expect_output "the failure says the files are live" "files are live but the towns import failed"
    unset STUB_FAIL
}

broken_boot_never_reads_as_skipped() {
    STUB_BROKEN_ENV=1
    export STUB_BROKEN_ENV
    clear_log
    run gate /dev/null purge
    expect_failure "purge fails on a console that does not boot"
    expect_output "purge says the console did not boot" "does not boot"
    case "$out" in
        *skipped*) fail "a broken boot reads as a build without #19" ;;
        *stub-secret-excerpt*) fail "the console's stderr reached the output" ;;
        *) ok "purge never reads a broken boot as skipped" ;;
    esac
    run gate /dev/null check
    expect_failure "check fails on a console that does not boot"
    expect_output "check says the console did not boot" "does not boot"
    unset STUB_BROKEN_ENV
}

purge_and_check() {
    clear_log
    run gate /dev/null purge
    expect_exit "purge exits 0" 0
    expect_output "purge without #19 says so" "no app:http-cache:clear in this build"
    expect_calls "purge runs in the app" "app list --raw"

    STUB_HTTP_CACHE=1
    export STUB_HTTP_CACHE
    clear_log
    run gate /dev/null purge
    unset STUB_HTTP_CACHE
    expect_calls "purge runs the clear when the build has it" "app list --raw
app app:http-cache:clear"

    clear_log
    run gate /dev/null check
    expect_exit "check exits 0" 0
    expect_output "check prints the sha" "sha=$SHA"
    expect_output "check prints the PHP version" "php=8.5.0-stub"
    expect_output "check prints the free disk" "free"
    expect_calls "check runs the guard in the app" "app list --raw
app app:prod:check-env"

    STUB_FAIL=app:prod:check-env
    export STUB_FAIL
    run gate /dev/null check
    unset STUB_FAIL
    expect_failure "check fails on a failing guard"
}

cron_midnight() {
    clear_log
    run sh "$APP/bin/cron-midnight"
    expect_exit "cron-midnight exits 0" 0
    expect_no_output "cron-midnight is silent"
    expect_calls "cron-midnight runs only the clear" "app app:http-cache:clear"
    expect_gone "cron-midnight releases the lock" "$APP/var/cache-clear.lock"

    STUB_PRINT=app:http-cache:clear
    export STUB_PRINT
    run sh "$APP/bin/cron-midnight"
    unset STUB_PRINT
    expect_exit "cron-midnight exits 0 when the clear prints its line" 0
    expect_no_output "a good night mails nothing, whatever the clear prints"

    STUB_FAIL=app:http-cache:clear
    export STUB_FAIL
    run sh "$APP/bin/cron-midnight"
    unset STUB_FAIL
    expect_failure "cron-midnight reports a failed clear"
    expect_output "cron-midnight says the clear failed" "cron-midnight: the clear failed"
    expect_output "the clear's own output follows" "stub: app:http-cache:clear failed"
    expect_gone "a failed clear releases the lock" "$APP/var/cache-clear.lock"

    clear_log
    run sh "$APP/bin/cron-cache-guard"
    expect_exit "cron-cache-guard exits 0" 0
    expect_calls "the guard clears only over the limit" "app app:http-cache:clear --if-over-limit"
    expect_gone "the guard releases the lock" "$APP/var/cache-clear.lock"
}

wait_for_file() { # wait_for_file <path>
    tries=0
    until [ -e "$1" ]; do
        tries=$((tries + 1))
        [ "$tries" -lt 400 ] || fail "$1 never appeared"
        sleep 0.05
    done
}

cron_lock() {
    clear_log
    rm -f "$work/go" "$work/started"
    STUB_HOLD="$work/go" STUB_STARTED="$work/started" sh "$APP/bin/cron-midnight" &
    midnight=$!
    wait_for_file "$work/started"
    run sh "$APP/bin/cron-cache-guard"
    expect_exit "the guard skips a tick quietly" 0
    expect_calls "the guard does not clear while midnight holds the lock" "app app:http-cache:clear"
    : > "$work/go"
    wait "$midnight" || fail "cron-midnight failed behind the guard"

    clear_log
    rm -f "$work/go" "$work/started"
    STUB_HOLD="$work/go" STUB_STARTED="$work/started" sh "$APP/bin/cron-cache-guard" &
    guard=$!
    wait_for_file "$work/started"
    STUB_HOLD="$work/go" sh "$APP/bin/cron-midnight" &
    midnight=$!
    # Long enough for a retry of the one-second wait.
    sleep 1.3
    expect_calls "midnight waits for the guard" "app app:http-cache:clear --if-over-limit"
    : > "$work/go"
    wait "$guard" || fail "the guard failed"
    wait "$midnight" || fail "cron-midnight failed after waiting"
    expect_calls "midnight clears once the guard is done" "app app:http-cache:clear --if-over-limit
app app:http-cache:clear"
    expect_gone "both release the lock" "$APP/var/cache-clear.lock"

    mkdir "$APP/var/cache-clear.lock"
    touch -t 202001010000 "$APP/var/cache-clear.lock"
    clear_log
    run sh "$APP/bin/cron-cache-guard"
    expect_calls "a stale lock is taken over" "app app:http-cache:clear --if-over-limit"
    expect_gone "the new owner releases it" "$APP/var/cache-clear.lock"

    mkdir "$APP/var/cache-clear.lock"
    run sh -c "cd '$APP' && . bin/cron-lock.sh && cache_lock_acquire 2"
    expect_failure "a fresh lock stays busy until the tries run out"
    rmdir "$APP/var/cache-clear.lock"
}

cron_daily() {
    rm -rf "$APP/var/import-staging" "$APP/var/sessions" "$APP/var/lock"
    clear_log
    run sh "$APP/bin/cron-daily"
    expect_exit "cron-daily exits 0 on a fresh server" 0
    expect_no_output "a fresh server prints nothing"

    mkdir -p "$APP/var/import-staging/abandoned" "$APP/var/import-staging/young" "$APP/var/sessions/prod"
    touch -t 202001010000 "$APP/var/import-staging/abandoned"
    echo old > "$APP/var/sessions/prod/sess_old"
    touch -t 202001010000 "$APP/var/sessions/prod/sess_old"
    echo young > "$APP/var/sessions/prod/sess_young"
    echo month > "$APP/var/sessions/prod/sess_29_days"
    touch -t "$(date -d "@$(($(date +%s) - 29 * 86400))" +%Y%m%d%H%M)" "$APP/var/sessions/prod/sess_29_days"
    echo stale > "$APP/var/sessions/prod/sess_31_days"
    touch -t "$(date -d "@$(($(date +%s) - 31 * 86400))" +%Y%m%d%H%M)" "$APP/var/sessions/prod/sess_31_days"
    mkdir -p "$APP/var/lock"
    touch "$APP/var/lock/sf.young.abc.lock" "$APP/var/lock/sf.old.abc.lock" "$APP/var/lock/sf.170-minutes.abc.lock" \
        "$APP/var/lock/not-a-limiter"
    touch -t 202001010000 "$APP/var/lock/sf.old.abc.lock" "$APP/var/lock/not-a-limiter"
    touch -t "$(date -d "@$(($(date +%s) - 170 * 60))" +%Y%m%d%H%M)" "$APP/var/lock/sf.170-minutes.abc.lock"

    clear_log
    run sh "$APP/bin/cron-daily"
    expect_exit "cron-daily exits 0 on a good night" 0
    expect_no_output "a good night prints nothing"
    expect_calls "cron-daily runs its seven commands in order" "app app:inquiries:purge
app app:crm:refresh-stages
app app:crm:renewal-tasks
app app:crm:purge
app app:crm:backfill-inquiries
app app:inquiries:report-stuck
app cache:pool:prune"
    expect_gone "an abandoned upload is swept" "$APP/var/import-staging/abandoned"
    [ -d "$APP/var/import-staging/young" ] || fail "a young upload was swept"
    ok "a young upload stays"
    expect_gone "an old session is swept" "$APP/var/sessions/prod/sess_old"
    expect_file "a young session stays" "$APP/var/sessions/prod/sess_young" young
    expect_file "a session idle for 29 days stays" "$APP/var/sessions/prod/sess_29_days" month
    expect_gone "a session idle for 31 days is swept" "$APP/var/sessions/prod/sess_31_days"
    expect_gone "an old limiter lock is swept" "$APP/var/lock/sf.old.abc.lock"
    [ -f "$APP/var/lock/sf.young.abc.lock" ] || fail "a young limiter lock was swept"
    [ -f "$APP/var/lock/sf.170-minutes.abc.lock" ] || fail "a lock under three hours was swept"
    ok "a limiter lock under three hours stays"
    [ -f "$APP/var/lock/not-a-limiter" ] || fail "the sweep took a file that is no limiter lock"
    ok "the sweep takes only limiter locks"

    STUB_FAIL=app:inquiries:purge
    export STUB_FAIL
    clear_log
    run sh "$APP/bin/cron-daily"
    unset STUB_FAIL
    expect_failure "a failed step fails cron-daily"
    expect_output "the failed step is named" "cron-daily: inquiries-purge failed"
    expect_output "the step's own output follows" "stub: app:inquiries:purge failed"
    expect_calls "the other steps still run" "app app:inquiries:purge
app app:crm:refresh-stages
app app:crm:renewal-tasks
app app:crm:purge
app app:crm:backfill-inquiries
app app:inquiries:report-stuck
app cache:pool:prune"

    STUB_PRINT=app:inquiries:report-stuck
    export STUB_PRINT
    run sh "$APP/bin/cron-daily"
    unset STUB_PRINT
    expect_exit "a stuck inquiry is no failure" 0
    expect_output "what report-stuck prints reaches the cron mail" "stub: app:inquiries:report-stuck says something"
}

write_site() { # write_site <listed extra path, or nothing>
    site="http://127.0.0.1:$PORT"
    mkdir -p "$work/site"
    cat > "$work/site-router.php" <<'ROUTER'
<?php
$uri = $_SERVER['REQUEST_URI'];
file_put_contents((string) getenv('WARM_LOG'), $uri."\n", FILE_APPEND);
if (str_starts_with($uri, '/broken')) {
    http_response_code(500);

    return;
}
if (str_starts_with($uri, '/gone')) {
    http_response_code(404);

    return;
}
$file = __DIR__.'/site'.$uri;
echo is_file($file) ? file_get_contents($file) : 'page';
ROUTER
    cat > "$work/site/sitemap.xml" <<SITEMAP
<?xml version="1.0" encoding="UTF-8"?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <sitemap><loc>$site/sitemap-pages.xml</loc></sitemap>
  <sitemap><loc>$site/sitemap-venues-1.xml</loc></sitemap>
</sitemapindex>
SITEMAP
    cat > "$work/site/sitemap-pages.xml" <<SITEMAP
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc>$site/hochzeitslocations</loc></url>
  <url><loc>$site/impressum</loc></url>
  <url><loc>$site/x?a=1&amp;b=2</loc></url>
${1:+  <url><loc>$site$1</loc></url>}
</urlset>
SITEMAP
    # One line on purpose: a sitemap is not always pretty-printed.
    printf '<urlset><url><loc>%s/hochzeitslocation/a</loc><image:image><image:loc>%s/media/1/a.jpg</image:loc></image:image></url><url><loc>%s/hochzeitslocation/b</loc></url><url><loc>%s/impressum</loc></url></urlset>\n' \
        "$site" "$site" "$site" "$site" > "$work/site/sitemap-venues-1.xml"
}

warm_cache() {
    WARM_LOG="$work/warm-log"
    export WARM_LOG
    : > "$WARM_LOG"
    write_site ""
    php -S "127.0.0.1:$PORT" "$work/site-router.php" > "$work/php-server.log" 2>&1 &
    server=$!
    tries=0
    until curl -fsS -o /dev/null --max-time 1 "http://127.0.0.1:$PORT/ready" 2>/dev/null; do
        tries=$((tries + 1))
        [ "$tries" -lt 100 ] || fail "the stub site did not start"
        sleep 0.05
    done

    : > "$WARM_LOG"
    run env DEPLOY_SITE_URL="http://127.0.0.1:$PORT" WARM_PAUSE=0 sh "$ROOT/bin/warm-cache"
    expect_exit "warm-cache exits 0" 0
    expect_output "warm-cache prints counts" "warm-cache: 5 warmed, 0 failed"
    [ "$(cat "$WARM_LOG")" = "/sitemap.xml
/sitemap-pages.xml
/sitemap-venues-1.xml
/hochzeitslocations
/impressum
/x?a=1&b=2
/hochzeitslocation/a
/hochzeitslocation/b" ] || fail "warm-cache requested other pages: $(cat "$WARM_LOG")"
    ok "the listing comes first, each page once, no image"

    write_site /broken
    : > "$WARM_LOG"
    run env DEPLOY_SITE_URL="http://127.0.0.1:$PORT" WARM_PAUSE=0 sh "$ROOT/bin/warm-cache"
    expect_exit "a failed page fails warm-cache" 1
    expect_output "the failed page is counted" "warm-cache: 5 warmed, 1 failed"

    rm "$work/site/sitemap.xml"
    run env DEPLOY_SITE_URL="http://127.0.0.1:$PORT" WARM_PAUSE=0 sh "$ROOT/bin/warm-cache"
    expect_failure "a site without a real index fails warm-cache"

    media_smoke

    kill "$server" 2>/dev/null || true
    wait "$server" 2>/dev/null || true
    server=""
}

# The workflow's own step, run the way GitHub runs it, without the retries that only cost time here.
media_smoke_step() { # media_smoke_step <site>
    sed -n '/name: "Smoke: a media file is not immutable"/,/^ *$/p' "$ROOT/.github/workflows/deploy.yaml" \
        | sed '1,2d; /^ *$/d; s/^ *//; s/--retry 3 --retry-delay 5//' > "$work/media-smoke"
    [ -s "$work/media-smoke" ] || fail "the media smoke step is not in deploy.yaml"
    (cd "$work" && SITE="$1" bash -e media-smoke)
}

media_smoke() {
    write_site ""
    run media_smoke_step "http://127.0.0.1:$PORT/broken"
    expect_failure "a 500 on the venue sitemap fails the media smoke check"
    expect_output "the status is named" "answered 500"
    run media_smoke_step "http://127.0.0.1:$PORT"
    expect_exit "the media smoke check passes on a venue sitemap with an image" 0
    run media_smoke_step "http://127.0.0.1:$PORT/gone"
    expect_exit "the media smoke check passes before the first import" 0
    expect_output "it says why it has nothing to look at" "no venue in the sitemap yet"
}

write_stub_tools() {
    mkdir -p "$work/tools"
    cat > "$work/tools/docker" <<'STUB'
#!/bin/sh
printf 'docker %s\n' "$*" >> "$STUB_LOG"
[ -z "${DATABASE_URL:-}" ] || printf 'docker env DATABASE_URL=%s\n' "$DATABASE_URL" >> "$STUB_LOG"
exit 0
STUB
    cat > "$work/tools/ssh" <<'STUB'
#!/bin/sh
printf 'ssh %s\n' "$*" >> "$STUB_LOG"
STUB
    cat > "$work/tools/rsync" <<'STUB'
#!/bin/sh
printf 'rsync %s\n' "$*" >> "$STUB_LOG"
[ -z "${STUB_RSYNC_OUTPUT:-}" ] || printf '%s\n' "$STUB_RSYNC_OUTPUT"
exit "${STUB_RSYNC_EXIT:-0}"
STUB
    chmod +x "$work/tools/docker" "$work/tools/ssh" "$work/tools/rsync"
}

prod_import() { # prod_import <args...>: the real script from a fake checkout, with stub tools
    env PATH="$work/tools:$PATH" PROD_DATABASE_URL="${TEST_DATABASE_URL-postgresql://fake-url}" PROD_SSH="${TEST_SSH-site@host.example}" \
        sh "$work/checkout/bin/prod-import" "$@"
}

prod_import_refusals() {
    clear_log
    for arguments in "import --prune" "all --prune" "regenerate --prune" "sync --prune" "import --prune=1" \
        "import --overwrite-cms-edits" "import --force" "cache:clear" "doctrine:database:drop --force" \
        "regenerate --venue=abc" "regenerate --venue=1 --venue=2" "import --venue=1"; do
        # shellcheck disable=SC2086 # Each string is several arguments.
        run prod_import $arguments
        [ "$rc" != 0 ] || fail "prod-import accepted '$arguments'"
    done
    ok "prod-import refuses --prune and anything that is no fixed command"
    expect_no_calls "a refused prod-import calls nothing"

    TEST_DATABASE_URL=""
    run prod_import import
    expect_failure "prod-import refuses without PROD_DATABASE_URL"
    expect_output "the refusal names PROD_DATABASE_URL" "PROD_DATABASE_URL is not set"
    unset TEST_DATABASE_URL
    TEST_SSH=""
    run prod_import import
    expect_failure "prod-import refuses without PROD_SSH"
    expect_output "the refusal names PROD_SSH" "PROD_SSH is not set"
    unset TEST_SSH
    expect_no_calls "a missing variable calls nothing"
}

prod_import_commands() {
    clear_log
    run prod_import import
    expect_exit "prod-import import exits 0" 0
    last="$(grep -n 'app:venues:import' "$STUB_LOG" | tail -n 1)"
    case "$last" in
        *"-e APP_ENV=prod -e DATABASE_URL -e MEDIA_ROOT=/var/www/html/var/prod-media/originals -e MEDIA_PUBLIC_DIR=/var/www/html/var/prod-media/public php "*) ok "the import gets the production env" ;;
        *) fail "the import's env is wrong" ;;
    esac
    [ "$(grep -c 'docker env DATABASE_URL=postgresql://fake-url' "$STUB_LOG")" -ge 1 ] || fail "the connection string did not reach compose"
    ok "the connection string reaches compose by its environment"
    if grep -v '^docker env ' "$STUB_LOG" | grep -q fake-url; then
        fail "the connection string is on an argument list"
    fi
    ok "the connection string is on no argument list"

    clear_log
    run prod_import all
    expect_exit "prod-import all exits 0" 0
    steps="$(sed -n 's/.*app:venues:import.*/import/p; s/^rsync .*var\/media\/content.*/sync-originals/p; s/^rsync .*public\/media.*/sync-public/p; s/^ssh .*app:http-cache:clear.*/purge/p' "$STUB_LOG")"
    [ "$steps" = "import
sync-originals
sync-public
purge" ] || fail "prod-import all ran another order: $steps"
    ok "all runs import, sync, purge"
    if grep -q '^rsync .*--delete' "$STUB_LOG"; then
        fail "the sync deletes on the server"
    fi
    ok "the sync never deletes on the server"

    clear_log
    run prod_import regenerate --venue=7
    expect_exit "prod-import regenerate exits 0" 0
    grep -q 'app:media:regenerate --venue=7' "$STUB_LOG" || fail "regenerate lost --venue"
    grep -q '^ssh .*app:http-cache:clear' "$STUB_LOG" || fail "regenerate did not purge"
    ok "regenerate passes --venue and ends in a purge"

    run prod_import help
    expect_output "help states the run order" "Run order: first deploy"
}

pull_backup() { # pull_backup <args...>
    env PATH="$work/tools:$PATH" PROD_SSH=site@host.example BACKUP_DIR="$work/backup" sh "$ROOT/bin/pull-media-backup" "$@"
}

pull_media_backup() {
    clear_log
    run pull_backup
    expect_exit "a pull exits 0" 0
    expect_calls "a pull copies both trees, additive" "rsync -rltz site@host.example:www/hochzeitslocation.ch/var/media/ $work/backup/var-media/
rsync -rltz site@host.example:www/hochzeitslocation.ch/public/media/ $work/backup/public-media/"

    clear_log
    run pull_backup --verify
    expect_exit "--verify passes when nothing differs" 0
    expect_calls "--verify compares checksums without copying" "rsync -rlnc --itemize-changes site@host.example:www/hochzeitslocation.ch/var/media/ $work/backup/var-media/
rsync -rlnc --itemize-changes site@host.example:www/hochzeitslocation.ch/public/media/ $work/backup/public-media/"

    STUB_RSYNC_OUTPUT='>fc...... photo.jpg'
    export STUB_RSYNC_OUTPUT
    run pull_backup --verify
    expect_exit "--verify fails on a difference" 1
    expect_output "the difference is named" "photo.jpg"
    STUB_RSYNC_OUTPUT='.d..t.... ./'
    run pull_backup --verify
    expect_exit "--verify ignores an item that needs no transfer" 0
    unset STUB_RSYNC_OUTPUT

    STUB_RSYNC_EXIT=12
    export STUB_RSYNC_EXIT
    run pull_backup --verify
    expect_exit "a lost connection never reads as no differences" 1
    unset STUB_RSYNC_EXIT

    run env PATH="$work/tools:$PATH" PROD_SSH=site@host.example BACKUP_DIR= sh "$ROOT/bin/pull-media-backup"
    expect_failure "a pull refuses without BACKUP_DIR"
}

prepare() {
    mkdir -p "$work" "$APP" "$DEPLOY_WORK" "$work/checkout/bin" "$work/checkout/var/prod-media/originals/content" "$work/checkout/var/prod-media/public"
    clear_log
    write_stub_php
    write_stub_tools
    build_release "$work/release"
    pack "$work/release" "$work/release.tgz"
    live_tree
    cp "$ROOT/bin/prod-import" "$work/checkout/bin/prod-import"
}

main() {
    prepare
    gate_refusals
    deploy_ok
    deploy_with_http_cache
    deploy_that_drops_a_bundle
    deploy_failures
    deploy_failures_after_the_sync
    broken_boot_never_reads_as_skipped
    purge_and_check
    cron_midnight
    cron_lock
    cron_daily
    warm_cache
    prod_import_refusals
    prod_import_commands
    pull_media_backup
    printf '%s\n' "run.sh: all cases passed"
}

main "$@"
