<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

/**
 * Enforces `trusted_hosts` in front of HttpCache (#20). `Request::getHost()` throws only once per request, and
 * HttpCache::lookup() swallows that one exception, records `lookup-failed` and passes the request on, so behind the
 * cache a foreign Host would get the full page instead of a 400. The kernel never sees the exception, so the check
 * has to run before the cache does.
 */
#[When(env: 'prod')]
#[AsDecorator('http_cache')]
final readonly class TrustedHostGuard implements HttpKernelInterface, TerminableInterface
{
    public function __construct(
        #[AutowireDecorated]
        private HttpKernelInterface&TerminableInterface $cache,
    ) {
    }

    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        try {
            $request->getHost();
        } catch (SuspiciousOperationException) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        return $this->cache->handle($request, $type, $catch);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->cache->terminate($request, $response);
    }
}
