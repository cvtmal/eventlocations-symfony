<?php

declare(strict_types=1);

namespace App\Shared\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * The key a rate limiter counts a client under (#20). `RateLimiterFactory` builds the limiter's id
 * from it, and that id lands in the lock file's name (FlockStore keeps digits and dots) and in the
 * cached state, so a raw IP would stay on disk. Same IP, same key, so the limit stays per IP; keyed
 * with the app secret, because a plain hash of the 2^32 IPv4 addresses can simply be listed.
 */
final readonly class ClientIpKey
{
    public function __construct(
        #[Autowire('%kernel.secret%')]
        #[\SensitiveParameter]
        private string $secret,
    ) {
    }

    public function of(Request $request): string
    {
        return hash_hmac('sha256', $request->getClientIp() ?? 'unknown', $this->secret);
    }
}
