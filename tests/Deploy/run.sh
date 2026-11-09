#!/bin/sh
# Runs the real deploy, cron and import scripts against a throwaway server tree (#20 Step 4.7, F15),
# through `make deploy-test`, inside the php container. Nothing here needs an account or a network.
# PHP is a stub that logs "<dir> <console command>", so the order of the calls is what is tested;
# the releases/, shared/, current and previous layout is the real one bin/deploy builds.
#
# Every case prints "ok - …". The first failure stops the run, prints the log, and exits 1.
set -eu

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SHA=0123456789abcdef0123456789abcdef01234567
OTHER_SHA=fedcba9876543210fedcba9876543210fedcba98
THIRD_SHA=3333333333333333333333333333333333333333
FOURTH_SHA=4444444444444444444444444444444444444444
FIFTH_SHA=5555555555555555555555555555555555555555
NEW_SHA=abcdefabcdefabcdefabcdefabcdefabcdefabcd
PORT=18097

work="$ROOT/var/deploy-test/$$-$(od -An -N4 -tx4 /dev/urandom | tr -d ' ')"
HOME="$work/home"
DEPLOY_ROOT="$work/srv/deine-eventlocation.ch"
DEPLOY_WORK="$DEPLOY_ROOT/.deploy"
DEPLOY_PHP="$work/stub-php"
STUB_LOG="$work/calls"
export HOME DEPLOY_ROOT DEPLOY_WORK DEPLOY_PHP STUB_LOG
APP="$DEPLOY_ROOT/current"
SHARED="$DEPLOY_ROOT/shared"
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
# A release directory reads "release", the current link "current" (cron resolves it, purge does not).
calls() {
    sed "s#$DEPLOY_ROOT/releases/[0-9a-f]\{40\}#release#; s#$APP#current#; s#$work/checkout#checkout#g" "$STUB_LOG"
}

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
    if [ -e "$2" ] || [ -L "$2" ]; then
        fail "$1: $2 is still there"
    fi
    ok "$1"
}
expect_link() { # expect_link <case> <link> <target>
    [ -L "$2" ] || fail "$1: $2 is no symlink"
    [ "$(readlink "$2")" = "$3" ] || fail "$1: $2 points at $(readlink "$2"), wanted $3"
    ok "$1"
}
expect_live() { # expect_live <case> <sha>
    expect_link "$1" "$APP" "releases/$2"
}
# Everything but the gate's own work directory: links with their targets, files with their sums.
tree_state() {
    (
        cd "$DEPLOY_ROOT"
        find . -path ./.deploy -prune -o -type f -exec cksum {} + | sort
        find . -path ./.deploy -prune -o -type l -print | sort | while read -r l; do
            printf '%s -> %s\n' "$l" "$(readlink "$l")"
        done
        find . -path ./.deploy -prune -o -type d -print | sort
    )
}
remember_tree() { tree_before="$(tree_state)"; }
expect_tree_unchanged() { # expect_tree_unchanged <case>
    [ "$tree_before" = "$(tree_state)" ] || fail "$1: the server tree changed"
    ok "$1"
}
expect_clean_gate() { # expect_clean_gate <case>
    [ ! -e "$DEPLOY_WORK/.lock" ] || fail "$1: the lock is left behind"
    [ ! -e "$STAGE" ] || fail "$1: the stage is left behind"
    if [ -L "$DEPLOY_ROOT/.current.new" ] || [ -L "$DEPLOY_ROOT/.previous.new" ]; then
        fail "$1: a temporary link is left behind"
    fi
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
    pwd -P > var/cache/prod/container
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
    mkdir -p "$1/bin" "$1/vendor" "$1/public/assets" "$1/public/admin-build/.vite"
    for script in deploy cron-lock.sh cron-midnight cron-cache-guard cron-daily cron-hourly; do
        cp "$ROOT/bin/$script" "$1/bin/$script"
    done
    echo console > "$1/bin/console"
    echo runtime > "$1/vendor/autoload_runtime.php"
    echo index > "$1/public/index.php"
    echo '{}' > "$1/public/assets/manifest.json"
    echo '{}' > "$1/public/admin-build/.vite/manifest.json"
}

# What the owner sets up once (docs/operations.md), plus state a live site has collected.
server_tree() {
    mkdir -p "$DEPLOY_ROOT/releases" "$SHARED/var/sessions/prod" "$SHARED/var/media/content" \
        "$SHARED/var/share/prod/http_cache" "$SHARED/public/media/1"
    echo secrets > "$SHARED/.env.local"
    chmod 600 "$SHARED/.env.local"
    echo session > "$SHARED/var/sessions/prod/sess_a"
    echo original > "$SHARED/var/media/content/photo.jpg"
    echo page > "$SHARED/var/share/prod/http_cache/page"
    echo variant > "$SHARED/public/media/1/a-360.jpg"
}

gate_refusals() {
    remember_tree
    clear_log
    for command in "deploy abc" "rm -rf /" "" "rollback now" "rollback $SHA" "deploy" "deploy " "deploy ${SHA}0" \
        "deploy ${SHA} ; id" "deploy $(printf '%s' "$SHA" | tr 'a-f' 'A-F')" "purge now" "check; id" "install $SHA"; do
        run gate /dev/null "$command"
        [ "$rc" = 2 ] || fail "the gate let '$command' through: exit $rc"
    done
    ok "the gate refuses everything but deploy, purge, check and rollback, with exit 2"
    expect_no_calls "a refused command runs nothing"
    expect_tree_unchanged "a refused command changes nothing"
}

deploy_first() {
    clear_log
    run gate "$work/release.tgz" "deploy $SHA"
    expect_exit "the first deploy exits 0" 0
    expect_output "deploy prints the sha" "DEPLOYED_SHA=$SHA"
    expect_output "a build without #19 skips the HTTP cache with a note" "no app:http-cache:clear in this build"
    expect_calls "every step runs in the new release, before the switch" "release list --raw
release app:prod:check-env
release doctrine:migrations:migrate --allow-no-migration
release cache:warmup
release app:geo:import-towns
release list --raw"
    expect_live "current points at the new release" "$SHA"
    expect_gone "a first deploy has no previous release" "$DEPLOY_ROOT/previous"

    release="$DEPLOY_ROOT/releases/$SHA"
    expect_link ".env.local is a link into shared" "$release/.env.local" "$SHARED/.env.local"
    { [ -f "$SHARED/.env.local" ] && [ ! -L "$SHARED/.env.local" ]; } || fail "shared/.env.local became a link"
    [ "$(stat -c %a "$SHARED/.env.local")" = 600 ] || fail "shared/.env.local lost its mode"
    ok "shared/.env.local stays a private file"
    for d in log sessions share media stats lock import-staging; do
        [ "$(readlink "$release/var/$d")" = "$SHARED/var/$d" ] || fail "var/$d is not shared"
        [ -d "$SHARED/var/$d" ] || fail "shared/var/$d was not created"
    done
    ok "every var/ directory but the cache is shared"
    expect_link "public/media is shared" "$release/public/media" "$SHARED/public/media"
    { [ -d "$release/var/cache/prod" ] && [ ! -L "$release/var/cache" ]; } || fail "the release has no cache of its own"
    ok "the release compiles its own cache"
    expect_file "sessions are seen through current" "$APP/var/sessions/prod/sess_a" session
    expect_file "originals are seen through current" "$APP/var/media/content/photo.jpg" original
    expect_file "the HTTP cache store is seen through current" "$APP/var/share/prod/http_cache/page" page
    expect_file "variants are seen through current" "$APP/public/media/1/a-360.jpg" variant
    expect_file "the sha is in the release's .env.prod.local" "$release/.env.prod.local" "SENTRY_RELEASE=$SHA"
    expect_clean_gate "a deploy leaves no lock, no stage and no temporary link"
}

deploy_second() {
    clear_log
    STUB_HTTP_CACHE=1
    export STUB_HTTP_CACHE
    run gate "$work/release.tgz" "deploy $OTHER_SHA"
    unset STUB_HTTP_CACHE
    expect_exit "a second deploy exits 0" 0
    expect_calls "a build with the command empties the HTTP cache last" "release list --raw
release app:prod:check-env
release doctrine:migrations:migrate --allow-no-migration
release cache:warmup
release app:geo:import-towns
release list --raw
release app:http-cache:clear"
    expect_live "current points at the second release" "$OTHER_SHA"
    expect_link "previous points at the first" "$DEPLOY_ROOT/previous" "releases/$SHA"
    expect_file "the first release keeps its sha" "$DEPLOY_ROOT/releases/$SHA/.env.prod.local" "SENTRY_RELEASE=$SHA"
    expect_file "the first release keeps its own container" "$DEPLOY_ROOT/releases/$SHA/var/cache/prod/container" \
        "$DEPLOY_ROOT/releases/$SHA"
    expect_file "the second release has its own container" "$DEPLOY_ROOT/releases/$OTHER_SHA/var/cache/prod/container" \
        "$DEPLOY_ROOT/releases/$OTHER_SHA"
    expect_file "shared state outlives the switch" "$APP/var/sessions/prod/sess_a" session
    expect_clean_gate "a second deploy cleans up"

    remember_tree
    clear_log
    run gate "$work/release.tgz" "deploy $OTHER_SHA"
    expect_failure "deploying the live sha again is refused"
    expect_output "the refusal says why" "is live already, nothing was changed"
    expect_no_calls "deploying the live sha again runs nothing"
    expect_tree_unchanged "deploying the live sha again changes nothing"
    expect_clean_gate "a refused redeploy cleans up"

    clear_log
    run gate "$work/release.tgz" "deploy $SHA"
    expect_failure "deploying the previous sha again is refused"
    expect_output "the refusal points at rollback" "is the previous release, use rollback"
    expect_no_calls "deploying the previous sha runs nothing"
    expect_tree_unchanged "deploying the previous sha keeps the release rollback needs"
}

rollback_and_forward() {
    clear_log
    run gate /dev/null rollback
    expect_exit "rollback exits 0" 0
    expect_output "rollback says what it did" "rolled back"
    expect_output "rollback prints the sha now live" "DEPLOYED_SHA=$SHA"
    expect_live "rollback makes the previous release current" "$SHA"
    expect_link "rollback keeps the release it left as previous" "$DEPLOY_ROOT/previous" "releases/$OTHER_SHA"
    expect_calls "rollback only empties the HTTP cache, in the release it went back to" "release list --raw"
    expect_clean_gate "rollback leaves no temporary link"

    run gate /dev/null rollback
    expect_exit "a second rollback goes forward again" 0
    expect_live "a second rollback makes the newer release current" "$OTHER_SHA"
    expect_link "and the older one previous" "$DEPLOY_ROOT/previous" "releases/$SHA"

    mkdir "$DEPLOY_WORK/.lock"
    remember_tree
    clear_log
    run gate /dev/null rollback
    expect_exit "rollback waits for no running deploy: it is refused" 2
    expect_output "the refusal says why" "another deploy is running"
    expect_tree_unchanged "a refused rollback changes nothing"
    [ -d "$DEPLOY_WORK/.lock" ] || fail "the refused rollback took the deploy's lock away"
    ok "the refused rollback keeps the deploy's lock"
    rmdir "$DEPLOY_WORK/.lock"

    mv "$DEPLOY_ROOT/previous" "$work/previous.saved"
    remember_tree
    clear_log
    run gate /dev/null rollback
    expect_failure "rollback without a previous release fails"
    expect_output "the failure says why" "no previous release"
    expect_no_calls "a rollback that cannot go anywhere runs nothing"
    expect_tree_unchanged "a rollback that cannot go anywhere changes nothing"
    mv "$work/previous.saved" "$DEPLOY_ROOT/previous"
}

# ls -t orders by the time a release was moved in; these deploys are faster than its resolution.
age_release() { # age_release <sha> <yyyy>
    touch -t "${2}01010000" "$DEPLOY_ROOT/releases/$1"
}

prune() {
    run gate "$work/release.tgz" "deploy $THIRD_SHA"
    expect_exit "a third deploy exits 0" 0
    [ "$(find "$DEPLOY_ROOT/releases" -mindepth 1 -maxdepth 1 -type d | wc -l | tr -d ' ')" = 3 ] || fail "three releases are no more than KEEP"
    ok "three releases are all kept"

    age_release "$SHA" 2020
    age_release "$OTHER_SHA" 2021
    age_release "$THIRD_SHA" 2022
    run gate "$work/release.tgz" "deploy $FOURTH_SHA"
    expect_exit "a fourth deploy exits 0" 0
    expect_output "the prune says what it removed" "pruned $SHA"
    expect_gone "the oldest release is pruned" "$DEPLOY_ROOT/releases/$SHA"
    for sha in "$OTHER_SHA" "$THIRD_SHA" "$FOURTH_SHA"; do
        [ -d "$DEPLOY_ROOT/releases/$sha" ] || fail "release $sha was pruned"
    done
    ok "the newest three stay"

    age_release "$OTHER_SHA" 2021
    age_release "$THIRD_SHA" 2022
    age_release "$FOURTH_SHA" 2023
    run env DEPLOY_KEEP=1 sh -c "SSH_ORIGINAL_COMMAND='deploy $FIFTH_SHA' sh '$ROOT/bin/deploy-gate' < '$work/release.tgz'"
    expect_exit "a deploy with KEEP=1 exits 0" 0
    expect_live "the fifth release is live" "$FIFTH_SHA"
    expect_link "the fourth is previous" "$DEPLOY_ROOT/previous" "releases/$FOURTH_SHA"
    [ -d "$DEPLOY_ROOT/releases/$FOURTH_SHA" ] || fail "the previous release was pruned"
    ok "the previous release is never pruned, whatever KEEP says"
    expect_gone "older releases go" "$DEPLOY_ROOT/releases/$THIRD_SHA"
    expect_gone "older releases go" "$DEPLOY_ROOT/releases/$OTHER_SHA"
    expect_file "pruning leaves shared state alone" "$APP/var/media/content/photo.jpg" original
}

deploy_failures() {
    remember_tree

    clear_log
    STUB_FAIL=app:prod:check-env
    export STUB_FAIL
    run gate "$work/release.tgz" "deploy $NEW_SHA"
    expect_failure "a failing env guard fails the deploy"
    expect_output "the guard's failure says nothing live changed" "the env guard refused, the live release is unchanged"
    expect_tree_unchanged "a failing env guard leaves the server tree, the new directory included"
    expect_calls "a failing env guard runs no migration" "release list --raw
release app:prod:check-env"
    expect_clean_gate "a failing env guard cleans up"

    for step in doctrine:migrations:migrate cache:warmup app:geo:import-towns; do
        clear_log
        STUB_FAIL="$step"
        run gate "$work/release.tgz" "deploy $NEW_SHA"
        expect_failure "a failing $step fails the deploy"
        expect_output "the failure of $step says the live release is unchanged" "the live release is unchanged"
        expect_tree_unchanged "a failing $step leaves the server tree"
        expect_clean_gate "a failing $step cleans up"
    done
    unset STUB_FAIL

    clear_log
    STUB_NO_GUARD=1
    export STUB_NO_GUARD
    run gate "$work/release.tgz" "deploy $NEW_SHA"
    unset STUB_NO_GUARD
    expect_failure "a release without the env guard is refused"
    expect_output "the refusal names the guard" "app:prod:check-env is missing"
    expect_tree_unchanged "a release without the env guard leaves the server tree"

    clear_log
    STUB_BROKEN_ENV=1
    export STUB_BROKEN_ENV
    run gate "$work/release.tgz" "deploy $NEW_SHA"
    unset STUB_BROKEN_ENV
    expect_failure "a console that does not boot fails the deploy"
    expect_output "the failure says the console did not boot" "does not boot"
    case "$out" in
        *"is missing in this release"*) fail "a broken boot reads as a release without the guard" ;;
        *stub-secret-excerpt*) fail "the console's stderr reached the output" ;;
        *) ok "a broken boot is named as such, and its stderr stays out" ;;
    esac
    expect_tree_unchanged "a console that does not boot leaves the server tree"

    for file in bin/console vendor/autoload_runtime.php public/index.php public/assets/manifest.json public/admin-build/.vite/manifest.json; do
        rm -rf "$work/broken"
        cp -R "$work/release" "$work/broken"
        rm "$work/broken/$file"
        pack "$work/broken" "$work/broken.tgz"
        clear_log
        run gate "$work/broken.tgz" "deploy $NEW_SHA"
        expect_failure "a tarball without $file is refused"
        expect_output "the refusal names $file" "the tarball lacks $file"
        expect_no_calls "a tarball without $file runs nothing"
    done
    expect_tree_unchanged "a broken tarball leaves the server tree"

    printf 'this is no tarball' > "$work/garbage"
    clear_log
    run gate "$work/garbage" "deploy $NEW_SHA"
    expect_failure "garbage on stdin fails the deploy"
    expect_no_calls "garbage on stdin runs nothing"
    expect_tree_unchanged "garbage on stdin leaves the server tree"
    expect_clean_gate "garbage on stdin cleans up"

    mkdir "$DEPLOY_WORK/.lock"
    clear_log
    run gate "$work/release.tgz" "deploy $NEW_SHA"
    expect_exit "a held lock refuses a second deploy" 2
    expect_output "the refusal says why" "another deploy is running"
    expect_no_calls "a held lock runs nothing"
    expect_tree_unchanged "a held lock leaves the server tree"
    [ -d "$DEPLOY_WORK/.lock" ] || fail "the refused deploy took the other run's lock away"
    ok "the refused deploy keeps the other run's lock"
    rmdir "$DEPLOY_WORK/.lock"

    mv "$SHARED/.env.local" "$work/env.local.saved"
    remember_tree
    clear_log
    run gate "$work/release.tgz" "deploy $NEW_SHA"
    expect_failure "a server without shared/.env.local is refused"
    expect_output "the refusal names the file" ".env.local is missing"
    expect_no_calls "a server without .env.local runs nothing"
    expect_tree_unchanged "a server without .env.local is left alone"
    mv "$work/env.local.saved" "$SHARED/.env.local"

    run sh "$APP/bin/deploy" install "$NEW_SHA"
    expect_failure "install refuses to run from a release"
    expect_output "the refusal says where it must run" "must run from the unpacked tarball"

    run sh "$work/release/bin/deploy" install abc
    expect_failure "install refuses a short sha on its own too"
}

deploy_failure_after_the_switch() {
    STUB_HTTP_CACHE=1
    STUB_FAIL=app:http-cache:clear
    export STUB_HTTP_CACHE STUB_FAIL
    clear_log
    run gate "$work/release.tgz" "deploy $NEW_SHA"
    unset STUB_HTTP_CACHE STUB_FAIL
    expect_failure "a failing purge after the switch fails the deploy"
    expect_output "the failure says the release is live" "the release is live but the HTTP cache could not be cleared"
    expect_live "the new release stays live" "$NEW_SHA"
    expect_clean_gate "a failing purge cleans up"
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
    expect_calls "purge runs in current" "current list --raw"

    STUB_HTTP_CACHE=1
    export STUB_HTTP_CACHE
    clear_log
    run gate /dev/null purge
    unset STUB_HTTP_CACHE
    expect_calls "purge runs the clear when the build has it" "current list --raw
current app:http-cache:clear"

    clear_log
    run gate /dev/null check
    expect_exit "check exits 0" 0
    expect_output "check prints the live sha" "sha=$NEW_SHA"
    expect_output "check prints the previous sha" "previous=$FIFTH_SHA"
    expect_output "check prints the number of releases" "releases=3"
    expect_output "check prints the PHP version" "php=8.5.0-stub"
    expect_output "check prints the free disk" "free"
    expect_calls "check runs the guard in current" "current list --raw
current app:prod:check-env"

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
    expect_calls "cron-midnight runs only the clear" "release app:http-cache:clear"
    expect_gone "cron-midnight releases the lock" "$APP/var/lock/cache-clear.lock"

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
    expect_gone "a failed clear releases the lock" "$APP/var/lock/cache-clear.lock"

    clear_log
    run sh "$APP/bin/cron-cache-guard"
    expect_exit "cron-cache-guard exits 0" 0
    expect_calls "the guard clears only over the limit" "release app:http-cache:clear --if-over-limit"
    expect_gone "the guard releases the lock" "$APP/var/lock/cache-clear.lock"
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
    expect_calls "the guard does not clear while midnight holds the lock" "release app:http-cache:clear"
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
    expect_calls "midnight waits for the guard" "release app:http-cache:clear --if-over-limit"
    : > "$work/go"
    wait "$guard" || fail "the guard failed"
    wait "$midnight" || fail "cron-midnight failed after waiting"
    expect_calls "midnight clears once the guard is done" "release app:http-cache:clear --if-over-limit
release app:http-cache:clear"
    expect_gone "both release the lock" "$APP/var/lock/cache-clear.lock"

    mkdir "$APP/var/lock/cache-clear.lock"
    touch -t 202001010000 "$APP/var/lock/cache-clear.lock"
    clear_log
    run sh "$APP/bin/cron-cache-guard"
    expect_calls "a stale lock is taken over" "release app:http-cache:clear --if-over-limit"
    expect_gone "the new owner releases it" "$APP/var/lock/cache-clear.lock"

    mkdir "$APP/var/lock/cache-clear.lock"
    run sh -c "cd '$APP' && . bin/cron-lock.sh && cache_lock_acquire 2"
    expect_failure "a fresh lock stays busy until the tries run out"
    rmdir "$APP/var/lock/cache-clear.lock"
}

cron_daily() {
    # A fresh server: bin/deploy made the shared directories, and they are empty.
    for d in import-staging sessions lock stats; do
        rm -rf "${SHARED:?}/var/$d"
        mkdir "$SHARED/var/$d"
    done
    clear_log
    run sh "$APP/bin/cron-daily"
    expect_exit "cron-daily exits 0 on a fresh server" 0
    expect_no_output "a fresh server prints nothing"
    [ -d "$APP/var/stats/spool" ] || fail "a fresh server has no stats spool directory"
    [ -d "$APP/var/stats/salt" ] || fail "a fresh server has no stats salt directory"
    ok "a fresh server gets the stats directories"

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
    expect_calls "cron-daily runs its thirteen commands in order" "release app:inquiries:purge
release app:venues:erase-complainants
release app:stats:purge-search-misses
release app:crm:refresh-stages
release app:crm:renewal-tasks
release app:subscriptions:expire
release app:subscriptions:remind
release app:last-minute:expire
release app:inquiries:request-feedback
release app:crm:backfill-inquiries
release app:stats:ingest
release app:portal:owner-reports
release cache:pool:prune"
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
    expect_calls "the other steps still run" "release app:inquiries:purge
release app:venues:erase-complainants
release app:stats:purge-search-misses
release app:crm:refresh-stages
release app:crm:renewal-tasks
release app:subscriptions:expire
release app:subscriptions:remind
release app:last-minute:expire
release app:inquiries:request-feedback
release app:crm:backfill-inquiries
release app:stats:ingest
release app:portal:owner-reports
release cache:pool:prune"

    STUB_PRINT=app:crm:backfill-inquiries
    export STUB_PRINT
    run sh "$APP/bin/cron-daily"
    unset STUB_PRINT
    expect_exit "a repaired inquiry is no failure" 0
    expect_output "what backfill prints reaches the cron mail" "stub: app:crm:backfill-inquiries says something"
}

cron_hourly() {
    clear_log
    run sh "$APP/bin/cron-hourly"
    expect_exit "cron-hourly exits 0" 0
    expect_no_output "an hour whose alert went out prints nothing"
    expect_calls "cron-hourly runs only the stuck-inquiry alert" "release app:inquiries:report-stuck"

    STUB_PRINT=app:inquiries:report-stuck
    export STUB_PRINT
    run sh "$APP/bin/cron-hourly"
    unset STUB_PRINT
    expect_exit "a failed staff mail is no cron failure" 0
    expect_output "what report-stuck prints reaches the cron mail" "stub: app:inquiries:report-stuck says something"

    STUB_FAIL=app:inquiries:report-stuck
    export STUB_FAIL
    run sh "$APP/bin/cron-hourly"
    unset STUB_FAIL
    expect_failure "cron-hourly reports a failed command"
    expect_output "cron-hourly names the failed step" "cron-hourly: inquiries-stuck failed"
    expect_output "the command's own output follows" "stub: app:inquiries:report-stuck failed"
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
  <url><loc>$site/eventlokale</loc></url>
  <url><loc>$site/impressum</loc></url>
  <url><loc>$site/x?a=1&amp;b=2</loc></url>
${1:+  <url><loc>$site$1</loc></url>}
</urlset>
SITEMAP
    # One line on purpose: a sitemap is not always pretty-printed.
    printf '<urlset><url><loc>%s/eventlokal/a</loc><image:image><image:loc>%s/media/1/a.jpg</image:loc></image:image></url><url><loc>%s/eventlokal/b</loc></url><url><loc>%s/impressum</loc></url></urlset>\n' \
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
/eventlokale
/impressum
/x?a=1&b=2
/eventlokal/a
/eventlokal/b" ] || fail "warm-cache requested other pages: $(cat "$WARM_LOG")"
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
[ -z "${GOOGLE_PLACES_API_KEY:-}" ] || printf 'docker env GOOGLE_PLACES_API_KEY=%s\n' "$GOOGLE_PLACES_API_KEY" >> "$STUB_LOG"
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
        "regenerate --venue=abc" "regenerate --venue=1 --venue=2" "import --venue=1" \
        "import-dry --overwrite-cms-edits" "import-dry --force" "import-dry extra" \
        "customers --force" "customers-dry extra" "crm-bootstrap --limit=1" \
        "places-find --limit=5" "places-apply-dry extra" "places-apply --dry-run"; do
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
    run prod_import import-dry
    expect_exit "prod-import import-dry exits 0" 0
    last="$(grep -n 'app:venues:import' "$STUB_LOG" | tail -n 1)"
    case "$last" in
        *"-e APP_ENV=prod -e DATABASE_URL -e MEDIA_ROOT=/var/www/html/var/prod-media/originals -e MEDIA_PUBLIC_DIR=/var/www/html/var/prod-media/public php "*" app:venues:import --dry-run") ok "import-dry runs the import's dry run with the production env" ;;
        *) fail "import-dry ran another command or env" ;;
    esac
    if grep -q -E '^(rsync|ssh) ' "$STUB_LOG"; then
        fail "import-dry synced or purged"
    fi
    ok "import-dry neither syncs nor purges"

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

    clear_log
    run prod_import crm-bootstrap
    expect_exit "prod-import crm-bootstrap exits 0" 0
    last="$(grep -n 'app:crm:bootstrap' "$STUB_LOG" | tail -n 1)"
    case "$last" in
        *"-e APP_ENV=prod -e DATABASE_URL -e MEDIA_ROOT=/var/www/html/var/prod-media/originals -e MEDIA_PUBLIC_DIR=/var/www/html/var/prod-media/public php "*" app:crm:bootstrap") ok "crm-bootstrap runs app:crm:bootstrap with the production env" ;;
        *) fail "crm-bootstrap ran another command or env" ;;
    esac

    run prod_import help
    expect_output "help states the run order" "Run order: first deploy"
    expect_output "help states the place ID order" "places-apply-dry until right"

    clear_log
    GOOGLE_PLACES_API_KEY=fake-places-key
    export GOOGLE_PLACES_API_KEY
    run prod_import places-find
    unset GOOGLE_PLACES_API_KEY
    expect_exit "prod-import places-find exits 0" 0
    last="$(grep -n 'app:venues:find-google-places' "$STUB_LOG" | tail -n 1)"
    case "$last" in
        *"-e GOOGLE_PLACES_API_KEY -e APP_ENV=prod -e DATABASE_URL -e MEDIA_ROOT=/var/www/html/var/prod-media/originals -e MEDIA_PUBLIC_DIR=/var/www/html/var/prod-media/public php "*" app:venues:find-google-places") ok "places-find runs the lookup with the production env" ;;
        *) fail "places-find ran another command or env" ;;
    esac
    grep -q '^docker env GOOGLE_PLACES_API_KEY=fake-places-key' "$STUB_LOG" || fail "the Places key did not reach compose"
    if grep -v '^docker env ' "$STUB_LOG" | grep -q fake-places-key; then
        fail "the Places key is on an argument list"
    fi
    ok "the Places key reaches compose by its environment, on no argument list"
    if grep -q -E '^(rsync|ssh) ' "$STUB_LOG"; then
        fail "places-find synced or purged"
    fi
    ok "places-find neither syncs nor purges"

    clear_log
    run prod_import places-apply-dry
    expect_exit "prod-import places-apply-dry exits 0" 0
    last="$(grep -n 'app:venues:apply-google-places' "$STUB_LOG" | tail -n 1)"
    case "$last" in
        *"-e APP_ENV=prod -e DATABASE_URL -e MEDIA_ROOT=/var/www/html/var/prod-media/originals -e MEDIA_PUBLIC_DIR=/var/www/html/var/prod-media/public php "*" app:venues:apply-google-places --dry-run") ok "places-apply-dry runs the dry run with the production env" ;;
        *) fail "places-apply-dry ran another command or env" ;;
    esac
    if grep -q -E '^(rsync|ssh) ' "$STUB_LOG"; then
        fail "places-apply-dry synced or purged"
    fi
    ok "places-apply-dry neither syncs nor purges"

    clear_log
    run prod_import places-apply
    expect_exit "prod-import places-apply exits 0" 0
    steps="$(sed -n 's/.*app:venues:apply-google-places --dry-run.*/dry-run/p; s/.*app:venues:apply-google-places$/apply/p; s/^ssh .*app:http-cache:clear.*/purge/p' "$STUB_LOG")"
    [ "$steps" = "apply
purge" ] || fail "places-apply ran another order: $steps"
    ok "places-apply applies, then purges"
}

pull_backup() { # pull_backup <args...>
    env PATH="$work/tools:$PATH" PROD_SSH=site@host.example BACKUP_DIR="$work/backup" sh "$ROOT/bin/pull-media-backup" "$@"
}

pull_media_backup() {
    clear_log
    run pull_backup
    expect_exit "a pull exits 0" 0
    expect_calls "a pull copies both trees, additive" "rsync -rltz site@host.example:/var/www/deine-eventlocation.ch/current/var/media/ $work/backup/var-media/
rsync -rltz site@host.example:/var/www/deine-eventlocation.ch/current/public/media/ $work/backup/public-media/"

    clear_log
    run pull_backup --verify
    expect_exit "--verify passes when nothing differs" 0
    expect_calls "--verify compares checksums without copying" "rsync -rlnc --itemize-changes site@host.example:/var/www/deine-eventlocation.ch/current/var/media/ $work/backup/var-media/
rsync -rlnc --itemize-changes site@host.example:/var/www/deine-eventlocation.ch/current/public/media/ $work/backup/public-media/"

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

probe() { # probe <app dir> <checks...>: deploy/probe.sh with a stub restic and without PHP
    dir="$1"
    shift
    env PATH="$work/probe-tools:$PATH" PROBE_APP_DIR="$dir" PROBE_PHP=false PROBE_ENV_FILE="$work/no-probe.env" \
        sh "$ROOT/deploy/probe.sh" "$@"
}

probe_media_backup() {
    mkdir -p "$work/probe-tools" "$work/probe/current/bin" "$work/probe/current/var/log"
    cat > "$work/probe-tools/restic" <<'STUB'
#!/bin/sh
printf 'stub restic %s\n' "$*"
STUB
    chmod +x "$work/probe-tools/restic"

    run probe "$work/probe-none" 14
    expect_exit "the probe exits 0 without a release" 0
    expect_output "check 14 says that there is no release yet" "skipped: no release in $work/probe-none/current yet"
    case "$out" in
        *"=== check 1:"* | *"=== check 13:"*) fail "a probe naming check 14 ran other checks" ;;
    esac
    ok "a probe naming check 14 runs that check alone"

    cp "$ROOT/bin/cron-media-backup" "$work/probe/current/bin/cron-media-backup"
    cat > "$work/probe/current/.env.local" <<'ENV'
MEDIA_BACKUP_REPOSITORY='s3:https://s3.example.ch/fake-bucket/site'
MEDIA_BACKUP_PASSWORD='fake-backup-password'
MEDIA_BACKUP_ACCESS_KEY_ID='fake-backup-key'
MEDIA_BACKUP_SECRET_ACCESS_KEY='fake-backup-secret'
ENV
    run probe "$work/probe" 14
    expect_output "check 14 lists the newest snapshot, without a lock" "stub restic --no-lock --no-cache snapshots --latest 1"
    expect_output "check 14 says that no backup has finished yet" "no backup has finished yet"
    expect_output "a missing backup fails check 14" "check 14: ended with exit 1"

    echo 2026-11-08T03:30:00Z > "$work/probe/current/var/log/media-backup.ok"
    run probe "$work/probe" 14
    expect_output "a fresh backup passes check 14" "the last backup finished at 2026-11-08T03:30:00Z"
    case "$out" in
        *"ended with exit"*) fail "a fresh backup failed check 14" ;;
    esac
    ok "a fresh backup ends check 14 without an exit code"

    touch -t 202001010000 "$work/probe/current/var/log/media-backup.ok"
    run probe "$work/probe" 14
    expect_output "a backup older than 26 hours says so" "more than 26 hours ago"
    expect_output "and fails check 14" "check 14: ended with exit 1"
}

prepare() {
    mkdir -p "$work" "$work/checkout/bin" "$work/checkout/var/prod-media/originals/content" "$work/checkout/var/prod-media/public" \
        "$work/checkout/content/owner"
    clear_log
    write_stub_php
    write_stub_tools
    build_release "$work/release"
    pack "$work/release" "$work/release.tgz"
    server_tree
    cp "$ROOT/bin/prod-import" "$work/checkout/bin/prod-import"
}

main() {
    prepare
    gate_refusals
    deploy_first
    deploy_second
    rollback_and_forward
    prune
    deploy_failures
    deploy_failure_after_the_switch
    broken_boot_never_reads_as_skipped
    purge_and_check
    cron_midnight
    cron_lock
    cron_daily
    cron_hourly
    warm_cache
    prod_import_refusals
    prod_import_commands
    pull_media_backup
    probe_media_backup
    printf '%s\n' "run.sh: all cases passed"
}

main "$@"
