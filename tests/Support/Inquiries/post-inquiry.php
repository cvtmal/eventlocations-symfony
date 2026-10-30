<?php

declare(strict_types=1);

/*
 * One POST of the inquiry form through the real kernel, in a process of its own, so it has its
 * own database connection and really commits. Run by SubmitInquiryConcurrencyTest, which passes
 * the environment on. Arguments: the slug and the JSON body. Prints the status code.
 */

use App\Kernel;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$slug = $argv[1] ?? '';
$body = json_decode($argv[2] ?? '', true, flags: \JSON_THROW_ON_ERROR);
assert(is_array($body));

$kernel = new Kernel('test', true);
$request = Request::create('/hochzeitslocation/'.$slug.'/anfrage', 'POST', $body, server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
$response = $kernel->handle($request);
fwrite(\STDOUT, (string) $response->getStatusCode());
$kernel->terminate($request, $response);
