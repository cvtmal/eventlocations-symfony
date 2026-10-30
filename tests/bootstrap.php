<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Filesystem\Filesystem;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

// A kernel with debug off never checks freshness, so createClient(['debug' => false]) would
// otherwise run on whatever container, routes, catalogues and Twig cache it first built.
(new Filesystem())->remove(dirname(__DIR__).'/var/cache/test');

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
