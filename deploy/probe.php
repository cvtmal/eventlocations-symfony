<?php

declare(strict_types=1);

/*
 * One-off web probe of the Hostpoint account (#20, plan Step 0.4). Copy it into the document root as
 * probe-<16 hex>.php, request it, delete it in the same session. It runs under no other name, so
 * the copy in this repository and a stray file do nothing.
 *
 *   curl -sS https://…/probe-<hex>.php                                  everything below
 *   curl -sS -H 'X-Forwarded-For: 203.0.113.9' https://…/probe-<hex>.php    what the proxy passes on
 *   curl -sS -F file=@25mb.bin https://…/probe-<hex>.php                what the body limit lets through
 *   curl -sS -o /dev/null -w '%{time_total}\n' 'https://…/probe-<hex>.php?sleep=70'   the gateway's cut-off
 *
 * Plain text. Never prints the Neon connection string or any part of it, nor a whole $_SERVER. The DSN
 * is read from ~/probe.env, see deploy/probe.sh.
 */

if (1 !== preg_match('/\Aprobe-[0-9a-f]{16}\.php\z/', basename(__FILE__))) {
    http_response_code(404);
    exit;
}

// Read before it is switched off below, so the report shows the host's own value.
$displayErrors = ini_get('display_errors');
ini_set('display_errors', '0');

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

function section(string $title): void
{
    printf("\n=== %s\n", $title);
}

function line(string $label, mixed $value): void
{
    printf("%s: %s\n", $label, is_scalar($value) ? var_export($value, true) : get_debug_type($value));
}

function sleepOnly(mixed $seconds): void
{
    if (!is_string($seconds) || 1 !== preg_match('/\A\d{1,2}\z/', $seconds) || (int) $seconds > 90) {
        http_response_code(400);
        echo "sleep takes 0 to 90\n";

        return;
    }

    // No flush: the gateway must wait as it would for a slow page, with no byte of the response yet.
    sleep((int) $seconds);
    printf("slept %d s\n", (int) $seconds);
}

function requestFacts(): void
{
    section('request');
    foreach (['REMOTE_ADDR', 'HTTPS', 'SERVER_SOFTWARE', 'REQUEST_METHOD'] as $key) {
        line($key, $_SERVER[$key] ?? null);
    }
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with((string) $key, 'HTTP_X_FORWARDED_')) {
            line((string) $key, $value);
        }
    }
}

function uploadFacts(): void
{
    section('body limit');
    line('CONTENT_LENGTH', $_SERVER['CONTENT_LENGTH'] ?? null);
    line('files PHP saw', count($_FILES));
    foreach ($_FILES as $file) {
        line('size', $file['size'] ?? null);
        line('upload error code', $file['error'] ?? null);
    }
}

function phpFacts(string|false $displayErrors): void
{
    section('php');
    line('PHP_VERSION', \PHP_VERSION);
    line('SAPI', \PHP_SAPI);
    $extensions = get_loaded_extensions();
    sort($extensions);
    printf("extensions: %s\n", implode(' ', $extensions));

    section('ini');
    line('display_errors', $displayErrors);
    foreach ([
        'memory_limit', 'upload_max_filesize', 'post_max_size', 'max_execution_time', 'default_socket_timeout',
        'user_ini.cache_ttl', 'realpath_cache_ttl', 'zend.exception_ignore_args', 'disable_functions',
        // An in-place deploy cannot reset php-fpm's OPcache: with timestamps off, or a long revalidate_freq, the
        // web keeps the old bytecode at the same paths.
        'opcache.enable', 'opcache.validate_timestamps', 'opcache.revalidate_freq',
    ] as $key) {
        line($key, ini_get($key));
    }
}

/** A pool's settings are no ini values, and only its live counts reach PHP. */
function fpmFacts(): void
{
    section('php-fpm');
    $maxRequests = ini_get('pm.max_requests');
    line('pm.max_requests', false === $maxRequests ? 'not visible' : $maxRequests);

    $status = function_exists('fpm_get_status') ? fpm_get_status() : false;
    if (false === $status) {
        line('worker count', 'not visible');

        return;
    }

    line('process manager', $status['process-manager'] ?? 'not visible');
    line('worker count', $status['total-processes'] ?? 'not visible');
    line('limit reached so far', $status['max-children-reached'] ?? 'not visible');
}

/** The limiters' lock store is flock in var/lock: without working locks every limited route throws. */
function flockFacts(): void
{
    section('flock');
    line('directory', __DIR__);

    $path = __DIR__.'/probe-flock-'.bin2hex(random_bytes(4));
    $first = @fopen($path, 'x+');
    if (false === $first) {
        echo "FAILED: the web user cannot create a file here\n";

        return;
    }

    try {
        $second = fopen($path, 'r+');
        if (false === $second) {
            echo "FAILED: the file does not open twice\n";

            return;
        }

        if (!flock($first, \LOCK_EX)) {
            echo "FAILED: flock() refused\n";

            return;
        }

        echo flock($second, \LOCK_EX | \LOCK_NB) ? "NOT ENFORCED: a second handle got the same lock\n" : "works\n";
    } finally {
        unlink($path);
    }
}

/** Hostpoint documents `Use php-fpm php85` as the per-folder form, and a deploy replaces this file. */
function htaccessFacts(): void
{
    section('.htaccess in the document root');
    $file = __DIR__.'/.htaccess';
    if (!is_readable($file)) {
        echo "none here\n";

        return;
    }

    $lines = preg_grep('/\bUse\s+php-fpm\b/i', file($file) ?: []) ?: [];
    if ([] === $lines) {
        echo "no Use php-fpm line\n";

        return;
    }

    foreach ($lines as $number => $text) {
        printf("line %d: %s\n", $number + 1, trim($text));
    }
    echo "public/.htaccess carries the pin and a deploy replaces this file: PHP_VERSION above proves it took.\n";
}

/** @return array{name: string, dir: string}|null */
function webAccount(): ?array
{
    if (!function_exists('posix_getpwuid') || !function_exists('posix_geteuid')) {
        return null;
    }

    return posix_getpwuid(posix_geteuid()) ?: null;
}

function logFacts(): void
{
    section('user and logs');
    line('web user', webAccount()['name'] ?? null);
    line('error_log setting', ini_get('error_log'));

    $marker = 'hochzeitslocation-probe-'.bin2hex(random_bytes(4));
    line('marker, look for it in the panel error log', $marker);
    line('error_log() returned', error_log($marker.' error_log'));
    line('php://stderr bytes written', @file_put_contents('php://stderr', $marker." stderr\n"));
}

function imageFacts(): void
{
    section('images');
    if (!function_exists('imagetypes')) {
        echo "no gd\n";

        return;
    }

    line('AVIF', (bool) (imagetypes() & \IMG_AVIF));
    line('WebP', (bool) (imagetypes() & \IMG_WEBP));
    if (class_exists(Imagick::class)) {
        line('Imagick AVIF formats', count(Imagick::queryFormats('AVIF')));
    }
    if (!function_exists('imageavif') || !(imagetypes() & \IMG_AVIF)) {
        return;
    }

    // Busy pixels: a flat image would encode in a fraction of a photo's time. q52 s6 is #5's setting.
    $image = imagecreatetruecolor(4000, 2667);
    mt_srand(20);
    for ($i = 0; $i < 20000; ++$i) {
        $x = mt_rand(0, 3990);
        $y = mt_rand(0, 2660);
        imagefilledrectangle($image, $x, $y, $x + mt_rand(1, 60), $y + mt_rand(1, 60), mt_rand(0, 0xFFFFFF));
    }

    $path = tempnam(sys_get_temp_dir(), 'probe');
    if (false === $path) {
        echo "no temp file\n";

        return;
    }

    try {
        $start = hrtime(true);
        $written = imageavif($image, $path, 52, 6);
        printf("imageavif 4000x2667: %s in %.1f s\n", $written ? 'ok' : 'FAILED', (hrtime(true) - $start) / 1e9);
    } finally {
        unlink($path);
    }
}

function probeDsn(): ?string
{
    $home = webAccount()['dir'] ?? null;
    $file = getenv('PROBE_ENV_FILE') ?: (null === $home ? null : $home.'/probe.env');
    if (null === $file || !is_readable($file)) {
        return null;
    }

    $content = (string) file_get_contents($file);
    if (1 !== preg_match('/^PROBE_DSN=(.*)$/m', $content, $match)) {
        return null;
    }

    return trim($match[1], " \t\r'\"");
}

function databaseFacts(): void
{
    section('neon');
    $dsn = probeDsn();
    if (null === $dsn) {
        echo "skipped: ~/probe.env not readable from the web user (open_basedir?)\n";

        return;
    }

    preg_match_all('/(?:host|user|password|dbname)=([^;]*)/', $dsn, $parts);

    try {
        $start = hrtime(true);
        $pdo = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 15]);
        printf("connected in %d ms\n", (hrtime(true) - $start) / 1_000_000);
        line('libpq', $pdo->getAttribute(PDO::ATTR_CLIENT_VERSION));

        $times = [];
        for ($i = 0; $i < 20; ++$i) {
            $start = hrtime(true);
            $pdo->query('SELECT 1')?->fetchColumn();
            $times[] = (hrtime(true) - $start) / 1_000_000;
        }
        sort($times);
        printf("SELECT 1, 20 times: median %.1f ms, slowest %.1f ms\n", $times[10], $times[19]);
    } catch (Throwable $e) {
        printf("FAILED: %s\n", str_replace(array_filter($parts[1]), '***', $e->getMessage()));
    }
}

if (isset($_GET['sleep'])) {
    sleepOnly($_GET['sleep']);
    exit;
}

requestFacts();

if ('POST' === ($_SERVER['REQUEST_METHOD'] ?? '')) {
    uploadFacts();
    exit;
}

phpFacts($displayErrors);
fpmFacts();
flockFacts();
htaccessFacts();
logFacts();
imageFacts();
databaseFacts();
