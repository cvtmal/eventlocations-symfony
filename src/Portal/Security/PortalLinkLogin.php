<?php

declare(strict_types=1);

namespace App\Portal\Security;

use App\Portal\Repository\PortalUserRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Logs the owner of a mail's link in once the link's form has set a password: registering, a reset and a staff
 * invitation all end on `/konto`, not on the login. Only the POST calls it, so a mail gateway's GET still changes
 * nothing. The identity is read fresh, because `PortalIdentity::isEqualTo` compares the hash that was just set.
 * The firewall's session strategy migrates the session, so an id planted before the login does not survive it.
 */
final class PortalLinkLogin
{
    public function __construct(
        private readonly PortalUserRepository $users,
        private readonly Security $security,
    ) {
    }

    public function logIn(string $portalUserId): void
    {
        $user = $this->users->find($portalUserId) ?? throw new \LogicException('The link was verified a moment ago, so the account exists.');

        // The firewall's response, a redirect to its target, is not used: the controller answers itself.
        $this->security->login(PortalIdentity::fromEntity($user), 'form_login', PortalArea::FIREWALL);
    }
}
