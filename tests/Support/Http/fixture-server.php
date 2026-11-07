<?php

declare(strict_types=1);

/*
 * Router of the throw-away web server that SafeFetcherTransportTest starts with `php -S` on 127.0.0.1: a few
 * well-behaved and a few hostile answers, so the real cURL transport can be tested. Every request path is appended
 * to the file named by FIXTURE_LOG. Not part of the application.
 */

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH);
$log = getenv('FIXTURE_LOG');
if (is_string($log) && '' !== $log) {
    file_put_contents($log, $path."\n", \FILE_APPEND | \LOCK_EX);
}

$header = static fn (string $name): string => htmlspecialchars((string) ($_SERVER['HTTP_'.strtoupper(str_replace('-', '_', $name))] ?? '-'));

switch ($path) {
    case '/':
        header('Content-Type: text/html; charset=utf-8');
        echo '<html><title>host='.$header('Host').' ua='.$header('User-Agent').' cookie='.$header('Cookie').' auth='.$header('Authorization').' encoding='.$header('Accept-Encoding').'</title></html>';
        break;
    case '/redirect':
        header('Location: /target', true, 302);
        break;
    case '/target':
        header('Content-Type: text/html');
        echo '<html>target</html>';
        break;
    case '/redirect-private':
        header('Location: http://169.254.169.254/latest/meta-data/', true, 302);
        break;
    case '/forever':
        header('Content-Type: text/html');
        for ($i = 0; $i < 20000 && !connection_aborted(); ++$i) {
            echo str_repeat('a', 8192);
            flush();
            usleep(200);
        }
        break;
    case '/idle':
        header('Content-Type: text/html');
        echo 'start';
        flush();
        sleep(6);
        break;
    case '/drip':
        header('Content-Type: text/html');
        for ($i = 0; $i < 40 && !connection_aborted(); ++$i) {
            echo 'x';
            flush();
            usleep(300000);
        }
        break;
    case '/huge-length':
        header('Content-Type: text/html');
        header('Content-Length: 900000000');
        echo 'short';
        break;
    case '/image':
        header('Content-Type: image/png');
        echo 'not really a png';
        break;
    default:
        http_response_code(404);
        echo 'not found';
}
