<?php

declare(strict_types=1);

namespace App\Admin\Security;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\PathRequestMatcher;

/**
 * The one answer to "is this request a back-office request?". A guard that compared the raw path would
 * have to repeat how Symfony reads it: the router and the firewall both decode it, so `/%61dmin/benutzer`
 * is `/admin/benutzer` to them. Asking the firewall map means the guards cover exactly what the firewall
 * covers, 404s under /admin included, which a route name could not.
 */
final class AdminArea
{
    private const string FIREWALL = 'admin';

    private const string JSON_PATHS = '^/admin/api/';

    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function contains(Request $request): bool
    {
        return self::FIREWALL === $this->security->getFirewallConfig($request)?->getName();
    }

    /**
     * `/admin/api/…` has no firewall of its own, so this one is a path after all, read by the matcher the
     * firewall uses.
     */
    public function isJson(Request $request): bool
    {
        return (new PathRequestMatcher(self::JSON_PATHS))->matches($request);
    }
}
