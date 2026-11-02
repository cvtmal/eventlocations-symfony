<?php

declare(strict_types=1);

namespace App\Portal\Security;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;

/**
 * The one answer to "is this request a portal request?", for the same reason as AdminArea: the router and
 * the firewall both decode the path, so `/%6Bonto/anmelden` is `/konto/anmelden` to them. Asking the
 * firewall map means the guards cover exactly what the firewall covers, 404s under /konto included.
 */
final class PortalArea
{
    public const string FIREWALL = 'portal';

    public const string SESSION_COOKIE = 'KONTOSESSID';

    public const string COOKIE_PATH = '/konto';

    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function contains(Request $request): bool
    {
        return self::FIREWALL === $this->security->getFirewallConfig($request)?->getName();
    }
}
