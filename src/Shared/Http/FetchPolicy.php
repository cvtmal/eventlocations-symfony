<?php

declare(strict_types=1);

namespace App\Shared\Http;

/**
 * What SafeFetcher refuses by default, and the only way to relax it. The container builds the strict policy
 * (no constructor argument); only a test or a throwaway script on a developer's machine asks for
 * `unsafeForLocalFixtures()`, to reach a fixture server on 127.0.0.1 through a stub resolver.
 */
final readonly class FetchPolicy
{
    public function __construct(
        /** Connect to loopback, private and other non-public addresses. */
        public bool $allowNonPublicAddresses = false,
        /** Connect to any port, not only 80 and 443. */
        public bool $allowAnyPort = false,
    ) {
    }

    public static function unsafeForLocalFixtures(): self
    {
        return new self(true, true);
    }
}
