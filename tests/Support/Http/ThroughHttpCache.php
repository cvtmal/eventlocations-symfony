<?php

declare(strict_types=1);

namespace App\Tests\Support\Http;

use App\Shared\Http\HttpCacheClearer;
use Symfony\Bundle\FrameworkBundle\HttpCache\HttpCache;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpCache\Store;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Requests through a hand-built HttpCache around the test kernel, on the store the real clearer
 * empties. `when@prod` is the only place the framework enables it, so a test wires it by hand,
 * with prod's trace level. The store starts and ends empty.
 */
trait ThroughHttpCache
{
    private HttpCache $httpCache;

    private function startHttpCache(KernelInterface $kernel): void
    {
        $this->httpCacheClearer()->clear();
        $store = new Store($kernel->getShareDir().'/http_cache');
        $this->httpCache = new HttpCache($kernel, $store, null, ['debug' => false, 'trace_level' => 'short']);
    }

    private function stopHttpCache(): void
    {
        $this->httpCacheClearer()->clear();
    }

    private function through(Request $request): Response
    {
        $response = $this->httpCache->handle($request);
        $this->httpCache->terminate($request, $response);

        return $response;
    }

    private function httpCacheClearer(): HttpCacheClearer
    {
        $clearer = self::getContainer()->get(HttpCacheClearer::class);
        self::assertInstanceOf(HttpCacheClearer::class, $clearer);

        return $clearer;
    }
}
