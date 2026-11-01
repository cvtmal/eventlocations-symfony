<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Http;

use App\Shared\Http\TrustedHostGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

final class TrustedHostGuardTest extends TestCase
{
    protected function setUp(): void
    {
        Request::setTrustedHosts(['^hochzeitslocation\.ch$']);
    }

    protected function tearDown(): void
    {
        Request::setTrustedHosts([]);
    }

    public function test_a_foreign_host_gets_a_400_and_never_reaches_the_cache(): void
    {
        $cache = new RecordingCache();

        $response = (new TrustedHostGuard($cache))->handle(Request::create('http://evil.example/hochzeitslocations'));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame(0, $cache->handled);
    }

    public function test_a_trusted_host_goes_through_the_cache(): void
    {
        $cache = new RecordingCache();

        $response = (new TrustedHostGuard($cache))->handle(Request::create('http://hochzeitslocation.ch/hochzeitslocations'));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(1, $cache->handled);
    }

    public function test_terminate_reaches_the_cache(): void
    {
        $cache = new RecordingCache();

        (new TrustedHostGuard($cache))->terminate(Request::create('http://hochzeitslocation.ch/'), new Response());

        self::assertSame(1, $cache->terminated);
    }
}

final class RecordingCache implements HttpKernelInterface, TerminableInterface
{
    public int $handled = 0;
    public int $terminated = 0;

    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        ++$this->handled;

        return new Response();
    }

    public function terminate(Request $request, Response $response): void
    {
        ++$this->terminated;
    }
}
