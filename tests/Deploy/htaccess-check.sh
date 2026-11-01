#!/bin/sh
# Runs public/.htaccess in a real Apache (#20, Step 4.6). Dev serves nginx, so nothing else ever
# reads that file before the live site does. Runs on the host, like make lighthouse, because it
# starts its own container; only curl and Docker are needed. Stops at the first failed case.
#
# index.php is a fixture served as text: there is no PHP here, and its marker in a response proves
# that the rewrite reached it. A CGI script stands in for php-fpm where a case needs a response
# header from a backend, because mod_headers documents the two in the same table.
set -eu

IMAGE="httpd:2.4.68-alpine"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
# Not under var/: tests/Deploy/run.sh runs as root in the container and can leave var/ root-owned, and
# this script runs on the host. Nothing mounts it, the fixtures reach Apache through `docker cp`.
work="$(mktemp -d)"
name="hochzeitslocation-htaccess-$$"
INDEX_MARKER="fixture front controller"

cleanup() {
    docker rm -f "$name" >/dev/null 2>&1 || true
    rm -rf "$work"
}
trap cleanup EXIT

ok() { printf '%s\n' "ok - $1"; }
fail() {
    printf '%s\n' "not ok - $1" >&2
    [ ! -f "$work/headers" ] || cat "$work/headers" >&2
    exit 1
}

# Only Apache's own defaults plus what .htaccess needs. deflate and rewrite are off in the image,
# and mod_filter must stay on: without it the deflate block is skipped and the gzip case proves nothing.
apache_conf() {
    cat <<'CONF'
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule deflate_module modules/mod_deflate.so
LoadModule cgid_module modules/mod_cgid.so
ServerName localhost
ScriptSock /tmp/cgid.sock
<Directory "/usr/local/apache2/htdocs">
    AllowOverride All
    Require all granted
</Directory>
<Files "backend.cgi">
    Options +ExecCGI
    SetHandler cgi-script
</Files>
CONF
}

fixtures() {
    tree="$work/htdocs"
    mkdir -p "$tree/assets" "$tree/admin-build" "$tree/media/1"
    cp "$ROOT/public/.htaccess" "$ROOT/public/.user.ini" "$tree/"
    printf '%s\n' "$INDEX_MARKER" > "$tree/index.php"
    # Long enough for mod_deflate, which leaves very short bodies alone.
    i=0
    while [ "$i" -lt 200 ]; do
        printf '.block-%s { color: red; margin: 0 auto; }\n' "$i"
        i=$((i + 1))
    done > "$tree/assets/app-abc123.css"
    cp "$tree/assets/app-abc123.css" "$tree/admin-build/x.js"
    printf 'fixture jpeg\n' > "$tree/media/1/a-360.jpg"
    cat > "$tree/backend.cgi" <<'CGI'
#!/bin/sh
printf 'Content-Type: text/plain\r\nX-Powered-By: PHP/8.5\r\nX-Fixture: backend\r\n\r\n'
printf 'authorization=%s\n' "${HTTP_AUTHORIZATION:-}"
CGI
    chmod 755 "$tree/backend.cgi"
    apache_conf > "$work/test.conf"
}

start_apache() {
    docker create --name "$name" -p 127.0.0.1::80 "$IMAGE" \
        sh -c 'cat /tmp/test.conf >> conf/httpd.conf && exec httpd-foreground' >/dev/null
    docker cp "$work/test.conf" "$name:/tmp/test.conf"
    docker cp "$work/htdocs/." "$name:/usr/local/apache2/htdocs/"
    docker start "$name" >/dev/null

    url="http://$(docker port "$name" 80 | head -n 1)"
    tries=0
    until curl -sS -o /dev/null --max-time 2 "$url/" 2>/dev/null; do
        tries=$((tries + 1))
        if [ "$tries" -ge 40 ]; then
            docker logs "$name" >&2
            fail "Apache did not start"
        fi
        sleep 0.25
    done
}

check_modules() {
    modules="$(docker exec "$name" httpd -M 2>/dev/null)"
    for module in rewrite_module headers_module deflate_module filter_module; do
        printf '%s\n' "$modules" | grep -q "^ $module " || fail "Apache lacks $module"
    done
    ok "rewrite, headers, deflate and filter are loaded"
}

# request <host> <path> [curl args...]: fills $work/headers and $work/body.
request() {
    host="$1"
    path="$2"
    shift 2
    curl -sS -o "$work/body" -D "$work/headers" -H "Host: $host" "$@" "$url$path"
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

SITE=hochzeitslocation.ch
WWW=www.hochzeitslocation.ch
TLS='X-Forwarded-Proto: https'

redirects() {
    request "$WWW" /x
    expect_redirect "www goes to the bare host over https in one hop" "https://$SITE/x"
    request "$WWW" /x -H "$TLS"
    expect_redirect "www redirects behind a TLS proxy too" "https://$SITE/x"
    request "$SITE" /x
    expect_redirect "plain http goes to https" "https://$SITE/x"
    request "$SITE" '/hochzeitslocations?canton=zh&page=2'
    expect_redirect "the scheme redirect keeps the query" "https://$SITE/hochzeitslocations?canton=zh&page=2"
    request "$WWW" '/hochzeitslocations?canton=zh'
    expect_redirect "the host redirect keeps the query" "https://$SITE/hochzeitslocations?canton=zh"
    request "$SITE" '/a%20b'
    expect_redirect "a redirect keeps an encoded space" "https://$SITE/a%20b"
    # %{REQUEST_URI} is the decoded path, so these two reach the rule as a real ? and a line break.
    request "$SITE" '/a%3Fb'
    [ "$(header location)" != "https://$SITE/a?b" ] || fail "an encoded ? turned into a query"
    ok "an encoded ? never turns into a query"
    request "$SITE" '/a%0d%0aSet-Cookie:x=1'
    expect_no_header "an encoded line break cannot add a header" set-cookie
    request "$SITE" /x -H "$TLS"
    expect_front_controller "a proxy that ended TLS gets no redirect"
}

front_controller() {
    for path in /robots.txt /sitemap.xml /hochzeitslocations /hochzeitslocation/seehaus-mittelwil /; do
        request "$SITE" "$path" -H "$TLS"
        expect_front_controller "$path reaches index.php"
    done
    request "$SITE" /media/1/ -H "$TLS"
    expect_front_controller "a directory is no listing, it reaches index.php"
    request "$SITE" /assets/app-abc123.css -H "$TLS"
    if grep -q "$INDEX_MARKER" "$work/body"; then
        fail "a real file must not reach index.php"
    fi
    ok "a real file is served as it is"
}

denied_files() {
    request "$SITE" /.user.ini -H "$TLS"
    expect_status "/.user.ini is denied" 403
    request "$SITE" /.htaccess -H "$TLS"
    expect_status "/.htaccess is denied" 403
}

caching() {
    request "$SITE" /assets/app-abc123.css -H "$TLS"
    expect_header_has "/assets/ is immutable" cache-control immutable
    expect_header_has "/assets/ is cached for a year" cache-control max-age=31536000
    expect_header_has "/assets/ has nosniff" x-content-type-options nosniff
    request "$SITE" /admin-build/x.js -H "$TLS"
    expect_header_has "/admin-build/ is immutable" cache-control immutable
    expect_header_has "/admin-build/ has nosniff" x-content-type-options nosniff
    request "$SITE" /media/1/a-360.jpg -H "$TLS"
    expect_header_has "/media/ is cached for a day" cache-control max-age=86400
    expect_header_lacks "/media/ is never immutable" cache-control immutable
    expect_header_has "/media/ has nosniff" x-content-type-options nosniff
}

compression() {
    request "$SITE" /assets/app-abc123.css -H "$TLS" -H 'Accept-Encoding: gzip'
    expect_header_has "CSS is compressed" content-encoding gzip
    request "$SITE" /admin-build/x.js -H "$TLS" -H 'Accept-Encoding: gzip'
    expect_header_has "the islands are compressed" content-encoding gzip
    request "$SITE" /assets/app-abc123.css -H "$TLS"
    expect_no_header "no compression unless asked for" content-encoding
    request "$SITE" /media/1/a-360.jpg -H "$TLS" -H 'Accept-Encoding: gzip'
    expect_no_header "an image stays as it is" content-encoding
}

backend() {
    request "$SITE" /backend.cgi -H "$TLS" -H 'Authorization: Bearer fixture'
    expect_header_has "the backend's own headers pass" x-fixture backend
    expect_no_header "X-Powered-By of a backend is removed" x-powered-by
    grep -q 'authorization=Bearer fixture' "$work/body" || fail "the Authorization header did not reach the backend"
    ok "the Authorization header reaches the backend"
}

main() {
    fixtures
    start_apache
    check_modules
    redirects
    front_controller
    denied_files
    caching
    compression
    backend
    printf '%s\n' "htaccess-check: all cases passed"
}

main "$@"
