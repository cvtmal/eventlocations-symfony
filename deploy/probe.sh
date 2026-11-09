#!/bin/sh
# Read-only check of the VPS, run by hand once as the deploy user after the first-time setup (#20, F15):
#
#   sh ~/probe/probe.sh | tee probe-cli.txt
#   sh ~/probe/probe.sh 14               only the checks named, here the media backup's age (F16)
#
# Prints the fourteen checks in order, one labelled block each. A failing check never stops the next.
# Leaves nothing on the server: only the flock check writes, and it removes its file. It never prints
# the Neon connection string or any part of it, so the output may go into the changes log as it is.
#
# ~/probe.env (chmod 600, outside the document root, deleted after the probe) holds one line, a PDO
# DSN rather than Neon's URL, because that is what `new PDO()` takes:
#
#   PROBE_DSN='pgsql:host=<ep-id>.eu-central-1.aws.neon.tech;dbname=<db>;user=<user>;password=<pw>;sslmode=require'
set -eu

PHP="${PROBE_PHP:-php}"
ENV_FILE="${PROBE_ENV_FILE:-$HOME/probe.env}"
APP_DIR="${PROBE_APP_DIR:-/var/www/deine-eventlocation.ch}"
SMTP_HOST="${PROBE_SMTP_HOST:-mail.infomaniak.com}"
SMTP_PORT="${PROBE_SMTP_PORT:-587}"

run() { # run <number> <title> <function>: skipped unless named on the command line, or none was
    case "$only" in
        "  " | *" $1 "*) ;;
        *) return 0 ;;
    esac
    printf '\n=== check %s: %s\n' "$1" "$2"
    "$3" || printf 'check %s: ended with exit %s\n' "$1" "$?"
}

# The DSN stays in a variable of the one command that needs it, never on an argv.
dsn() {
    [ -r "$ENV_FILE" ] || return 0
    sed -n 's/^PROBE_DSN=//p' "$ENV_FILE" | head -n 1 | sed "s/^['\"]//; s/['\"]\$//"
}

neon_host() {
    dsn | sed -n 's/.*host=\([^;]*\).*/\1/p'
}

port_open() { # port_open <host> <port>
    if command -v nc >/dev/null 2>&1; then
        nc -z -w5 "$1" "$2" >/dev/null 2>&1
    else
        # shellcheck disable=SC2016 # PHP code, not shell.
        "$PHP" -r 'exit(false === @fsockopen($argv[1], (int) $argv[2], $code, $message, 5) ? 1 : 0);' "$1" "$2"
    fi
}

bounded() { # bounded <command...>: a hanging server must not hang the probe
    if command -v timeout >/dev/null 2>&1; then
        timeout 20 "$@"
    else
        "$@"
    fi
}

php_version() {
    "$PHP" -v
}

php_paths() {
    command -v "$PHP"
    ls -l "$(dirname "$(command -v "$PHP")")"/php*
}

php_pgsql() {
    "$PHP" -m | grep -i pgsql
}

php_images() {
    "$PHP" <<'CODE'
<?php
echo 'AVIF: ', (imagetypes() & IMG_AVIF) ? 'yes' : 'no', PHP_EOL;
echo 'WebP: ', (imagetypes() & IMG_WEBP) ? 'yes' : 'no', PHP_EOL;
CODE
    "$PHP" -m | grep -E -i 'gd|imagick|exif|intl|sodium|opcache'
}

host_tools() {
    uname -a
    for tool in git rsync tar gzip curl sh bash; do
        printf '%s: %s\n' "$tool" "$(command -v "$tool" || echo missing)"
    done
    printf 'SHELL=%s\n' "${SHELL:-}"
}

app_dir() {
    ls -ld "$APP_DIR" "$APP_DIR/releases" "$APP_DIR/shared"
}

neon_port() {
    host="$(neon_host)"
    if [ -z "$host" ]; then
        printf 'skipped: no PROBE_DSN in %s\n' "$ENV_FILE"
        return 0
    fi
    if port_open "$host" 5432; then
        printf 'neon port 5432: open\n'
    else
        printf 'neon port 5432: CLOSED\n'
    fi
}

neon_query() {
    value="$(dsn)"
    if [ -z "$value" ]; then
        printf 'skipped: no PROBE_DSN in %s\n' "$ENV_FILE"
        return 0
    fi
    PROBE_DSN="$value" "$PHP" <<'CODE'
<?php
$dsn = (string) getenv('PROBE_DSN');
preg_match_all('/(?:host|user|password|dbname)=([^;]*)/', $dsn, $parts);
$redact = static fn (string $text): string => str_replace(array_filter($parts[1]), '***', $text);
$base = (string) preg_replace('/;?channel_binding=[^;]*/', '', $dsn);

foreach (['channel_binding=require' => $base.';channel_binding=require', 'no channel_binding' => $base] as $label => $try) {
    try {
        $start = hrtime(true);
        $pdo = new PDO($try, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 15]);
        printf("%s: connected in %d ms\n", $label, (hrtime(true) - $start) / 1_000_000);
    } catch (Throwable $e) {
        printf("%s: FAILED %s\n", $label, $redact($e->getMessage()));
        continue;
    }

    printf("  libpq %s\n", $pdo->getAttribute(PDO::ATTR_CLIENT_VERSION));
    printf("  server %s\n", $pdo->query('SHOW server_version')->fetchColumn());
    printf("  idle_in_transaction_session_timeout %s\n", $pdo->query('SHOW idle_in_transaction_session_timeout')->fetchColumn());

    $times = [];
    for ($i = 0; $i < 20; ++$i) {
        $start = hrtime(true);
        $pdo->query('SELECT 1')->fetchColumn();
        $times[] = (hrtime(true) - $start) / 1_000_000;
    }
    sort($times);
    printf("  SELECT 1, 20 times: median %.1f ms, slowest %.1f ms\n", $times[10], $times[19]);
}
CODE
}

host_time() {
    date
    date -u
    printf 'TZ=%s\n' "${TZ:-}"
}

host_disk() {
    id
    df -h "$HOME"
    du -sh "$HOME"
}

smtp() {
    if port_open "$SMTP_HOST" "$SMTP_PORT"; then
        printf '%s:%s: open\n' "$SMTP_HOST" "$SMTP_PORT"
    else
        printf '%s:%s: CLOSED\n' "$SMTP_HOST" "$SMTP_PORT"
        return 0
    fi
    if ! command -v openssl >/dev/null 2>&1; then
        printf 'skipped: no openssl\n'
        return 0
    fi
    # Only the 250 lines: the EHLO capabilities, SMTPUTF8 among them.
    { sleep 3; printf 'EHLO probe\nQUIT\n'; sleep 3; } \
        | bounded openssl s_client -starttls smtp -crlf -quiet -connect "$SMTP_HOST:$SMTP_PORT" 2>/dev/null \
        | grep '^250'
}

php_limits() {
    "$PHP" -i | grep -E 'memory_limit|realpath_cache_ttl'
    "$PHP" -d memory_limit=512M -r 'echo ini_get("memory_limit"), PHP_EOL;'
    # public/.user.ini never reaches the CLI, so the console has its own values. pm.max_requests and the
    # worker count belong to the php-fpm pool (deploy/php-fpm/deine-eventlocation.conf).
    "$PHP" <<'CODE'
<?php
foreach (['zend.exception_ignore_args', 'disable_functions'] as $key) {
    printf("%s: %s\n", $key, var_export(ini_get($key), true));
}
CODE
}

# The limiters' lock store is flock in var/lock: without working locks every limited route throws. The
# app directory may not exist yet, and the home directory is the same filesystem.
flock_works() {
    dir="$APP_DIR"
    [ -d "$dir" ] || dir="$HOME"
    printf 'directory: %s\n' "$dir"
    PROBE_FLOCK_DIR="$dir" "$PHP" <<'CODE'
<?php
$path = getenv('PROBE_FLOCK_DIR').'/probe-flock-'.bin2hex(random_bytes(4));
$first = @fopen($path, 'x+');
if (false === $first) {
    exit("FAILED: cannot create a file here\n");
}
$second = fopen($path, 'r+');
$locked = flock($first, LOCK_EX);
$enforced = !flock($second, LOCK_EX | LOCK_NB);
unlink($path);
echo $locked ? ($enforced ? "works\n" : "NOT ENFORCED: a second handle got the same lock\n") : "FAILED: flock() refused\n";
CODE
}

# F16: the last media backup finished less than 26 hours ago. The stamp is written only after restic
# succeeded; the repository's newest snapshot shows the backup arrived. --no-lock keeps restic read-only.
media_backup() {
    script="$APP_DIR/current/bin/cron-media-backup"
    if [ ! -f "$script" ]; then
        printf 'skipped: no release in %s/current yet\n' "$APP_DIR"
        return 0
    fi
    bounded sh "$script" restic --no-lock snapshots --latest 1 || printf 'restic: the snapshot list failed\n'
    sh "$script" status
}

main() {
    only=" $* "
    run 1 'PHP version' php_version
    run 2 'PHP binaries' php_paths
    run 3 'pdo_pgsql' php_pgsql
    run 4 'AVIF, WebP and modules' php_images
    run 5 'host and tools' host_tools
    run 6 'app directory and docroot' app_dir
    run 7 'outbound to Neon' neon_port
    run 8 'Neon: libpq, channel binding, latency, timeout' neon_query
    run 9 'server time zone' host_time
    run 10 'user and disk' host_disk
    run 11 'outbound SMTP and EHLO' smtp
    run 12 'CLI limits' php_limits
    run 13 'flock on the app filesystem' flock_works
    run 14 'media backup age' media_backup
}

main "$@"
