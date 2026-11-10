#!/bin/sh
# Boots the built tarball with APP_ENV=prod (#20, Step 4.8): `make release-check`, after
# bin/build-release. It starts on the host and goes on inside the php container, because the
# host has another PHP. Unlike every other test this one sees only what ships: no dev packages,
# no var/, no node_modules, and a .env.local as the server has it, with fictional values.
#
# Every check prints "ok - …"; the first failure stops the run and exits 1.
set -eu

if [ "${RELEASE_CHECK_IN_CONTAINER:-}" != 1 ]; then
    exec docker compose exec -T -e RELEASE_CHECK_IN_CONTAINER=1 php sh tests/Deploy/release-check.sh
fi

TARBALL="$PWD/var/release/release.tgz"
CHECK="$PWD/var/release/check"
MEDIA="$PWD/var/release/media"
ROUTER="$PWD/var/release/check-router.php"
DATABASE=app_release_check
PORT=8099
URL="http://127.0.0.1:$PORT"
SITE=deine-eventlocation.ch
server=""
database_exists=""

cleanup() {
    [ -z "$server" ] || kill "$server" 2>/dev/null || true
    [ -z "$server" ] || wait "$server" 2>/dev/null || true
    if [ -n "$database_exists" ]; then
        console doctrine:database:drop --force --if-exists >/dev/null 2>&1 || printf '%s\n' "release-check: $DATABASE was not dropped" >&2
    fi
    rm -rf "$CHECK" "$MEDIA" "$ROUTER"
}
trap cleanup EXIT

ok() { printf '%s\n' "ok - $1"; }
fail() {
    printf '%s\n' "not ok - $1" >&2
    exit 1
}

console() { (cd "$CHECK" && php bin/console --no-interaction "$@"); }

must() { # must <case> <console args...>
    label="$1"
    shift
    console "$@" > "$CHECK/var-output" 2>&1 || {
        tail -n 30 "$CHECK/var-output" >&2
        fail "$label"
    }
    ok "$label"
}

unpack() {
    [ -f "$TARBALL" ] || fail "$TARBALL is missing, run bin/build-release first"
    rm -rf "$CHECK" "$MEDIA"
    mkdir -p "$CHECK" "$MEDIA/originals" "$MEDIA/public"
    tar -xzf "$TARBALL" -C "$CHECK"
    ok "the tarball unpacks"
}

check_contents() {
    for file in .env public/.user.ini public/index.php bin/deploy bin/cron-midnight \
        bin/cron-cache-guard bin/cron-daily bin/cron-hourly bin/cron-lock.sh data/geo/towns.csv \
        public/assets/manifest.json public/admin-build/.vite/manifest.json; do
        [ -e "$CHECK/$file" ] || fail "the tarball lacks $file"
    done
    ok "the tarball holds what production runs on"
    for path in .env.local .env.dev .env.test tests var content docs deploy docker admin-ui compose.yaml compose.perf.yaml compose.review.yaml CLAUDE.md; do
        [ ! -e "$CHECK/$path" ] || fail "the tarball must not ship $path"
    done
    ok "the tarball holds nothing that must not ship"
    if grep -q use_putenv "$CHECK/vendor/autoload_runtime.php"; then
        fail "the built runtime puts every secret into the process environment"
    fi
    ok "the built runtime has no use_putenv"
}

# What the owner writes by hand on the server, with fictional values. The two values the guard
# must refuse are the only ones that are unsafe: this Compose database and a null mailer, which is
# all a check without an account can use.
write_env() {
    secret="$(php -r 'echo bin2hex(random_bytes(32));')"
    key="$(php -r 'echo base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));')"
    cat > "$CHECK/.env.local" <<ENV
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=$secret
ADMIN_TOTP_KEY=$key
ADMIN_DEV_AUTOLOGIN=
DEFAULT_URI=https://$SITE
DATABASE_URL="postgresql://app:app@database:5432/$DATABASE?serverVersion=18&charset=utf8"
MAILER_DSN=null://null
MAILER_FROM="deine-eventlocation.ch <hallo@$SITE>"
MEDIA_ROOT=$MEDIA/originals
MEDIA_PUBLIC_DIR=$MEDIA/public
ENV
}

check_env_guard() {
    rc=0
    console app:prod:check-env > "$CHECK/var-output" 2>&1 || rc=$?
    [ "$rc" = 1 ] || {
        cat "$CHECK/var-output" >&2
        fail "app:prod:check-env must exit 1 here, it gave $rc"
    }
    names="$(sed -n 's/^\([A-Z_]*\) .*/\1/p' "$CHECK/var-output" | sort -u | tr '\n' ' ')"
    [ "$names" = "DATABASE_URL MAILER_DSN " ] || {
        cat "$CHECK/var-output" >&2
        fail "app:prod:check-env named '$names', wanted exactly DATABASE_URL and MAILER_DSN"
    }
    # D27: while an operator fact is a TODO(owner) placeholder, the release's own container refuses it too.
    wanted="$(grep -c "^    site\.operator_[a-z]*: 'TODO(owner)" config/services.yaml || true)"
    placeholders="$(grep -c '^site\.operator_[a-z]* in config/services.yaml must be' "$CHECK/var-output" || true)"
    [ "$placeholders" = "$wanted" ] || {
        cat "$CHECK/var-output" >&2
        fail "app:prod:check-env named $placeholders operator placeholders, config/services.yaml holds $wanted (D27)"
    }
    ok "the env guard runs in the release and names exactly DATABASE_URL, MAILER_DSN and the $wanted operator placeholders"
}

# review-demo: the release's .env keeps the demo flag off, and with it on in .env.local the release refuses to run:
# App\Kernel refuses the flag on any database but the review stack's, app:prod:check-env refuses it outright.
check_review_mode_refused() {
    [ "$(sed -n 's/^APP_REVIEW_MODE=//p' "$CHECK/.env")" = 0 ] || fail "the release's .env must set APP_REVIEW_MODE=0"
    cp "$CHECK/.env.local" "$CHECK/.env.local.saved"
    printf 'APP_REVIEW_MODE=1\n' >> "$CHECK/.env.local"
    rc=0
    console app:prod:check-env > "$CHECK/var-output" 2>&1 || rc=$?
    mv "$CHECK/.env.local.saved" "$CHECK/.env.local"
    [ "$rc" != 0 ] || fail "app:prod:check-env passed with APP_REVIEW_MODE=1"
    grep -q 'Refusing to boot: .*APP_REVIEW_MODE' "$CHECK/var-output" || {
        cat "$CHECK/var-output" >&2
        fail "the release booted with APP_REVIEW_MODE=1 or did not name it"
    }
    ok "the release ships APP_REVIEW_MODE=0 and refuses to boot with APP_REVIEW_MODE=1 outside the review stack"
}

boot() {
    must "the prod container warms up" cache:warmup
    must "the prod container lints" lint:container

    console doctrine:database:drop --force --if-exists >/dev/null 2>&1 || true
    must "the check's database is created" doctrine:database:create
    database_exists=1
    must "the migrations run" doctrine:migrations:migrate --allow-no-migration
    must "the towns import" app:geo:import-towns
}

check_config() {
    console debug:config framework trusted_hosts > "$CHECK/var-output"
    grep -qF '^deine-eventlocation\.ch$' "$CHECK/var-output" || fail "trusted_hosts lacks the bare host"
    grep -qF '^www\.deine-eventlocation\.ch$' "$CHECK/var-output" || fail "trusted_hosts lacks www"
    grep -qF '^deineeventlocation\.ch$' "$CHECK/var-output" || fail "trusted_hosts lacks the redirect domain"
    ok "the three hosts are trusted"
    console debug:config sentry > "$CHECK/var-output"
    grep -q 'send_default_pii: false' "$CHECK/var-output" || fail "Sentry may send personal data"
    ok "Sentry sends no personal data"
    console debug:container 'App\Venues\HttpCachePurger' > "$CHECK/var-output"
    grep -q 'StoreClearingHttpCachePurger' "$CHECK/var-output" || fail "the purger does not clear the store"
    ok "a purge reaches the HTTP cache store"
}

# `php -S … public/index.php` would hand /assets/… to Symfony too, and without a router a path with
# a dot, such as /robots.txt, is a 404 of the built-in server. nginx's try_files does this part.
serve() {
    cat > "$ROUTER" <<'ROUTER'
<?php
$root = $_SERVER['DOCUMENT_ROOT'];
if (is_file($root.parse_url($_SERVER['REQUEST_URI'], \PHP_URL_PATH))) {
    return false;
}
$_SERVER['SCRIPT_FILENAME'] = $root.'/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
require $root.'/index.php';
ROUTER
    (cd "$CHECK" && exec php -S "127.0.0.1:$PORT" -t public "$ROUTER") > "$CHECK/server-output" 2>&1 &
    server=$!
    tries=0
    until curl -sS -o /dev/null --max-time 2 "$URL/" 2>/dev/null; do
        tries=$((tries + 1))
        [ "$tries" -lt 100 ] || {
            cat "$CHECK/server-output" >&2
            fail "the server did not start"
        }
        sleep 0.1
    done
}

code() { # code <host> <path>
    curl -sS -o "$CHECK/var-body" -w '%{http_code}' --max-time 30 -H "Host: $1" "$URL$2"
}

expect() { # expect <host> <path> <status>
    actual="$(code "$1" "$2")"
    [ "$actual" = "$3" ] || {
        tail -n 30 "$CHECK/var-body" >&2
        fail "$1$2 answered $actual, wanted $3"
    }
    ok "$2 answers $3 for $1"
}

check_pages() {
    for path in /health /robots.txt /eventlokale /impressum /admin/login /konto/anmelden; do
        expect "$SITE" "$path" 200
    done

    code "$SITE" /eventlokale >/dev/null
    stylesheet="$(grep -o 'href="/assets/[^"]*\.css"' "$CHECK/var-body" | head -n 1 | sed 's/^href="//; s/"$//')"
    [ -n "$stylesheet" ] || fail "the listing links no stylesheet"
    expect "$SITE" "$stylesheet" 200

    curl -sS -o /dev/null -D "$CHECK/var-headers" --max-time 30 -H "Host: $SITE" "$URL/eventlokale"
    grep -qi '^x-symfony-cache:.*fresh' "$CHECK/var-headers" || {
        cat "$CHECK/var-headers" >&2
        fail "the listing did not come from the HTTP cache"
    }
    ok "the HTTP cache answers a repeated listing"

    expect evil.example /eventlokale 400
}

main() {
    unpack
    check_contents
    write_env
    check_env_guard
    check_review_mode_refused
    boot
    check_config
    serve
    check_pages
    printf '%s\n' "release-check: all checks passed"
}

main "$@"
