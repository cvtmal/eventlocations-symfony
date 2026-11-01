<?php

declare(strict_types=1);

/*
 * One consume() of a rate limiter through the real container, in a process of its own, so a lock
 * the test holds can block it. Run by RateLimiterLockTest. Arguments: the limiter's service id and
 * the limiter key. Prints "ready" right before the consume and "consumed" after it.
 */

use App\Kernel;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$kernel = new Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
assert($container instanceof Psr\Container\ContainerInterface);
$factory = $container->get($argv[1] ?? '');
assert($factory instanceof RateLimiterFactoryInterface);
$limiter = $factory->create($argv[2] ?? '');

fwrite(\STDOUT, "ready\n");
$limiter->consume();
fwrite(\STDOUT, "consumed\n");
