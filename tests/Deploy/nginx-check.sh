#!/bin/sh
# Runs deploy/nginx/deine-eventlocation.ch.conf in a real nginx in front of the Compose php-fpm (F15).
# Dev serves docker/nginx/default.conf, so nothing else reads the production file before the live
# site does. Runs on the host, like make lighthouse, because it starts its own container; needs
# curl, openssl, Docker and the running `php` service. Stops at the first failed case.
#
# The file is used as it is, apart from what only the VPS has: the certificate paths (a throwaway
# self-signed one here), the php-fpm socket (the php service's port 9000), the release root and the
# IPv6 listens. The fixture tree is a releases/ + current layout, copied to the same path into both
# containers, so php-fpm finds the script under the path nginx resolved.
set -eu

IMAGE="nginx:1.26.3-alpine"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
CONF="$ROOT/deploy/nginx/deine-eventlocation.ch.conf"
# Not under var/: tests/Deploy/run.sh runs as root in the container and can leave var/ root-owned, and
# this script runs on the host. Nothing mounts it, the fixtures reach both containers through `docker cp`.
work="$(mktemp -d)"
name="eventlocation-nginx-$$"
FIX="/tmp/nginx-check-$$"
INDEX_MARKER="fixture front controller"
php_cid=""

cleanup() {
    docker rm -f "$name" >/dev/null 2>&1 || true
    [ -z "$php_cid" ] || docker exec "$php_cid" rm -rf "$FIX" >/dev/null 2>&1 || true
    rm -rf "$work"
}
trap cleanup EXIT

ok() { printf '%s\n' "ok - $1"; }
fail() {
    printf '%s\n' "not ok - $1" >&2
    [ ! -f "$work/headers" ] || cat "$work/headers" >&2
    exit 1
}

SITE=deine-eventlocation.ch
ALIASES="www.deine-eventlocation.ch deineeventlocation.ch www.deineeventlocation.ch"

release_fixture() { # release_fixture <name>
    public="$work/root/releases/$1/public"
    mkdir -p "$public/assets" "$public/admin-build" "$public/media/1"
    cat > "$public/index.php" <<'PHP'
<?php
if (str_starts_with($_SERVER['REQUEST_URI'], '/fixture-503')) {
    http_response_code(503);
}
header('X-Powered-By: PHP/8.5');
header('X-Fixture: backend');
echo "fixture front controller\n";
echo 'release=', basename(dirname(__DIR__)), "\n";
echo 'authorization=', $_SERVER['HTTP_AUTHORIZATION'] ?? '', "\n";
echo 'uri=', $_SERVER['REQUEST_URI'], "\n";
echo 'query=', $_SERVER['QUERY_STRING'] ?? '', "\n";
// Long enough for gzip_min_length.
echo str_repeat("<p>padding for the compression case</p>\n", 60);
PHP
    printf 'memory_limit=256M\n' > "$public/.user.ini"
    cp "$ROOT/public/50x.html" "$public/50x.html"
    printf '<?php echo "must never run";\n' > "$public/other.php"
    i=0
    while [ "$i" -lt 200 ]; do
        printf '.block-%s { color: red; margin: 0 auto; }\n' "$i"
        i=$((i + 1))
    done > "$public/assets/app-abc123.css"
    cp "$public/assets/app-abc123.css" "$public/admin-build/x.js"
    printf 'fixture jpeg\n' > "$public/media/1/a-360.jpg"
    printf 'fixture avif\n' > "$public/media/1/a-360.avif"
}

fixtures() {
    release_fixture r1
    release_fixture r2
    ln -s releases/r1 "$work/root/current"
    mkdir -p "$work/root/acme/.well-known/acme-challenge" "$work/cert"
    printf 'acme token\n' > "$work/root/acme/.well-known/acme-challenge/t"
    chmod -R a+rX "$work/root"

    openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=$SITE" \
        -keyout "$work/cert/privkey.pem" -out "$work/cert/fullchain.pem" >/dev/null 2>&1 \
        || fail "openssl could not make the test certificate"

    sed -e "s#/var/www/deine-eventlocation.ch/current/public#$FIX/current/public#" \
        -e "s#/var/www/letsencrypt#$FIX/acme#" \
        -e "s#unix:/run/php/deine-eventlocation.sock#php:9000#" \
        -e "s#/etc/letsencrypt/live/deine-eventlocation.ch/#/etc/nginx/test-cert/#" \
        -e '/listen \[::\]/d' \
        "$CONF" > "$work/site.conf"
    for path in "$FIX/current/public" "$FIX/acme" php:9000 /etc/nginx/test-cert/; do
        grep -q "$path" "$work/site.conf" || fail "the production file no longer has the line this check rewrites to $path"
    done
}

start_nginx() {
    php_cid="$(cd "$ROOT" && docker compose ps -q php)"
    [ -n "$php_cid" ] || fail "the php service is not running (make up)"
    network="$(docker inspect -f '{{range $k, $v := .NetworkSettings.Networks}}{{$k}} {{end}}' "$php_cid" | cut -d' ' -f1)"

    docker exec "$php_cid" mkdir -p "$FIX"
    docker cp "$work/root/." "$php_cid:$FIX/"
    docker exec "$php_cid" chmod -R a+rX "$FIX"

    docker create --name "$name" --network "$network" -p 127.0.0.1::80 -p 127.0.0.1::443 "$IMAGE" >/dev/null
    docker cp "$work/site.conf" "$name:/etc/nginx/conf.d/default.conf"
    docker cp "$work/cert/." "$name:/etc/nginx/test-cert/"
    docker cp "$work/root/." "$name:$FIX/"
    docker start "$name" >/dev/null

    http="$(docker port "$name" 80 | head -n 1)"
    https="$(docker port "$name" 443 | head -n 1)"
    # A request without one of our names is the default server's 444, which curl reports as exit 52.
    tries=0
    until {
        curl -sS -o /dev/null --max-time 2 "http://$http/" 2>/dev/null
        [ "$?" = 52 ]
    }; do
        tries=$((tries + 1))
        if [ "$tries" -ge 40 ]; then
            docker logs "$name" >&2
            fail "nginx did not start"
        fi
        sleep 0.25
    done
    docker exec "$name" nginx -t >/dev/null 2>&1 || fail "nginx -t refuses the production file"
    ok "nginx accepts the production file"
}

# request <http|https> <host> <path> [curl args...]: fills $work/headers and $work/body, $rc is curl's exit.
request() {
    scheme="$1"
    host="$2"
    path="$3"
    shift 3
    case "$scheme" in
        http) to="$host:80:$http" ;;
        *) to="$host:443:$https" ;;
    esac
    : > "$work/headers"
    : > "$work/body"
    rc=0
    curl -sS -k -o "$work/body" -D "$work/headers" --connect-to "$to" "$@" "$scheme://$host$path" 2>/dev/null || rc=$?
}

status() { sed -n '1s/^HTTP[^ ]* \([0-9]*\).*/\1/p' "$work/headers" | tr -d '\r'; }
header() { grep -i "^$1:" "$work/headers" | head -n 1 | sed 's/^[^:]*: *//' | tr -d '\r'; }

expect_redirect() { # expect_redirect <case> <location>
    [ "$(status)" = 301 ] || fail "$1: status $(status), wanted 301"
    [ "$(header location)" = "$2" ] || fail "$1: location $(header location), wanted $2"
    ok "$1"
}

expect_front_controller() { # expect_front_controller <case>
    [ "$(status)" = 200 ] || fail "$1: status $(status), wanted 200"
    grep -q "$INDEX_MARKER" "$work/body" || fail "$1: the body is not index.php"
    ok "$1"
}

expect_body_has() { # expect_body_has <case> <text>
    grep -q "$2" "$work/body" || fail "$1: the body lacks '$2'"
    ok "$1"
}

expect_header_has() { # expect_header_has <case> <header> <text>
    case "$(header "$2")" in
        *"$3"*) ok "$1" ;;
        *) fail "$1: $2 is '$(header "$2")', wanted '$3' in it" ;;
    esac
}

expect_header_lacks() { # expect_header_lacks <case> <header> <text>
    case "$(header "$2")" in
        *"$3"*) fail "$1: $2 is '$(header "$2")'" ;;
        *) ok "$1" ;;
    esac
}

expect_no_header() { # expect_no_header <case> <header>
    [ -z "$(header "$2")" ] || fail "$1: $2 is '$(header "$2")'"
    ok "$1"
}

expect_status() { # expect_status <case> <status>
    [ "$(status)" = "$2" ] || fail "$1: status $(status), wanted $2"
    ok "$1"
}

redirects() {
    for host in "$SITE" $ALIASES; do
        request http "$host" '/eventlocations?canton=zh&page=2'
        expect_redirect "http://$host goes to the canonical https host in one hop, query kept" \
            "https://$SITE/eventlocations?canton=zh&page=2"
    done
    for host in $ALIASES; do
        request https "$host" /x
        expect_redirect "https://$host goes to the canonical host" "https://$SITE/x"
    done
    request http "$SITE" '/a%20b'
    expect_redirect "a redirect keeps an encoded space" "https://$SITE/a%20b"
    request http "$SITE" '/a%0d%0aSet-Cookie:x=1'
    expect_no_header "an encoded line break cannot add a header" set-cookie
    request http "$SITE" /.well-known/acme-challenge/t
    expect_status "certbot's challenge is served over plain http" 200
    expect_body_has "the challenge comes from the webroot" "acme token"
}

unknown_hosts() {
    request http example.org /
    [ "$rc" = 52 ] || fail "an unknown host over http got an answer (curl exit $rc)"
    ok "an unknown host over http gets no answer"
    request https example.org /
    [ "$rc" != 0 ] || fail "an unknown host over https got a TLS session"
    ok "an unknown host over https gets no TLS session"
}

front_controller() {
    # places-photos: the two Google photo routes have a location of their own (limit_conn) and still reach PHP.
    for path in / /robots.txt /sitemap.xml /eventlocations /eventlocation/seehaus-mittelwil /eventlokal/seehaus-mittelwil/google-fotos /eventlokal/seehaus-mittelwil/google-fotos/AbC_1-2; do
        request https "$SITE" "$path"
        expect_front_controller "$path reaches index.php"
    done
    request https "$SITE" /assets/app-abc123.css
    if grep -q "$INDEX_MARKER" "$work/body"; then
        fail "a real file must not reach index.php"
    fi
    ok "a real file is served as it is"
    request https "$SITE" /assets/app-gone.css
    expect_status "a missing asset is a 404, not a page" 404
    request https "$SITE" /media/1/
    expect_status "a directory is no listing" 404
}

denied_files() {
    for path in /index.php /other.php /.user.ini /.env; do
        request https "$SITE" "$path"
        expect_status "$path is not served" 404
        if grep -q "must never run\|memory_limit" "$work/body"; then
            fail "$path leaked its content"
        fi
    done
}

caching() {
    request https "$SITE" /assets/app-abc123.css
    expect_header_has "/assets/ is immutable" cache-control immutable
    expect_header_has "/assets/ is cached for a year" cache-control max-age=31536000
    expect_header_has "/assets/ has nosniff" x-content-type-options nosniff
    request https "$SITE" /admin-build/x.js
    expect_header_has "/admin-build/ is immutable" cache-control immutable
    expect_header_has "/admin-build/ has nosniff" x-content-type-options nosniff
    request https "$SITE" /media/1/a-360.jpg
    expect_header_has "/media/ is cached for a day" cache-control max-age=86400
    expect_header_lacks "/media/ is never immutable" cache-control immutable
    expect_header_has "/media/ has nosniff" x-content-type-options nosniff
    # nosniff makes the browser trust the type, so AVIF variants need the right one.
    request https "$SITE" /media/1/a-360.avif
    expect_header_has "an AVIF variant is served as image/avif" content-type image/avif
    expect_header_lacks "the server header carries no version" server nginx/
}

compression() {
    request https "$SITE" /assets/app-abc123.css -H 'Accept-Encoding: gzip'
    expect_header_has "CSS is compressed" content-encoding gzip
    request https "$SITE" /admin-build/x.js -H 'Accept-Encoding: gzip'
    expect_header_has "the islands are compressed" content-encoding gzip
    request https "$SITE" /eventlocations -H 'Accept-Encoding: gzip'
    expect_header_has "PHP's HTML is compressed" content-encoding gzip
    request https "$SITE" /assets/app-abc123.css
    expect_no_header "no compression unless asked for" content-encoding
    request https "$SITE" /media/1/a-360.jpg -H 'Accept-Encoding: gzip'
    expect_no_header "an image stays as it is" content-encoding
}

backend() {
    request https "$SITE" /x -H 'Authorization: Bearer fixture'
    expect_header_has "the backend's own headers pass" x-fixture backend
    expect_no_header "X-Powered-By of php-fpm is removed" x-powered-by
    expect_body_has "the Authorization header reaches PHP" "authorization=Bearer fixture"
    request https "$SITE" '/x?canton=zh&page=2'
    expect_body_has "the path reaches PHP" "uri=/x?canton=zh&page=2"
    expect_body_has "the query string reaches PHP" "query=canton=zh&page=2"

    head -c 28000000 /dev/zero > "$work/big"
    request https "$SITE" /admin/upload --data-binary "@$work/big"
    expect_status "a body above 26 MB is nginx's 413" 413
    rm -f "$work/big"
}

# G5, G9, G10, G11: a preview, cancellation or opt-out link's token, or a preview's short code, never reaches the access log, neither as the path nor as a Referer;
# nor does the Google photo name in a places-photos image path (D12).
preview_not_logged() {
    request https "$SITE" /vorschau/secret-path-fixture
    request https "$SITE" /eventlokal/fixture/google-fotos/secret-photo-fixture
    request https "$SITE" /assets/app-abc123.css -H "Referer: https://$SITE/vorschau/secret-referer-fixture"
    request https "$SITE" /konto/abo-kuendigen/secret-cancel-fixture
    request https "$SITE" /assets/app-abc123.css -H "Referer: https://$SITE/konto/abo-kuendigen/secret-cancel-referer"
    request https "$SITE" /v/secret-short-fixture
    request https "$SITE" /assets/app-abc123.css -H "Referer: https://$SITE/v/secret-short-referer-fixture"
    request https "$SITE" /konto/bericht-abbestellen/secret-optout-fixture
    request https "$SITE" /assets/app-abc123.css -H "Referer: https://$SITE/konto/bericht-abbestellen/secret-optout-referer"
    request https "$SITE" /logged-fixture
    docker logs "$name" > "$work/access.log" 2>&1
    grep -q "/logged-fixture" "$work/access.log" || fail "an ordinary request is no longer in the access log"
    if grep -q "secret-" "$work/access.log"; then
        fail "a preview, cancellation or opt-out token or a Google photo name reached the access log"
    fi
    ok "a preview, cancellation or opt-out link's token and a Google photo name are not in the access log"
}

# bin/deploy's switch, done the same way: the next request runs the other release.
release_switch() {
    request https "$SITE" /x
    expect_body_has "current serves r1" "release=r1"
    docker exec "$name" sh -c "ln -sfn releases/r2 $FIX/.current.new && mv -T $FIX/.current.new $FIX/current"
    request https "$SITE" /x
    expect_body_has "after the switch the next request runs r2" "release=r2"
    request https "$SITE" /assets/app-abc123.css
    expect_status "static files follow the switch" 200
}

# F16 N8: nginx's own 502/503/504 shows public/50x.html with the status kept; the app's own 5xx passes.
# Last, because it points fastcgi_pass at a closed port.
server_down() {
    request https "$SITE" /fixture-503
    expect_status "the app's own 503 keeps its status" 503
    expect_body_has "and its own body" "$INDEX_MARKER"
    request https "$SITE" /50x.html
    expect_status "the error page is not reachable on its own" 404

    docker exec "$name" sed -i 's#fastcgi_pass php:9000;#fastcgi_pass 127.0.0.1:9;#' /etc/nginx/conf.d/default.conf
    docker exec "$name" nginx -s reload >/dev/null 2>&1 || fail "nginx refused the reload"
    tries=0
    until request https "$SITE" /eventlokale && [ "$(status)" = 502 ]; do
        tries=$((tries + 1))
        [ "$tries" -lt 40 ] || fail "php-fpm out of reach: status $(status), wanted 502"
        sleep 0.25
    done
    ok "php-fpm out of reach is a 502"
    expect_body_has "the 502 shows the German error page" "Wir sind gleich wieder da"
    expect_header_has "the error page is never cached" cache-control no-store
    expect_header_has "and is HTML" content-type text/html

    docker exec "$name" rm "$FIX/current/public/50x.html"
    request https "$SITE" /eventlokale
    expect_status "a release without the page stays a 502" 502
}

main() {
    fixtures
    start_nginx
    redirects
    unknown_hosts
    front_controller
    denied_files
    caching
    compression
    backend
    preview_not_logged
    release_switch
    server_down
    printf '%s\n' "nginx-check: all cases passed"
}

main "$@"
