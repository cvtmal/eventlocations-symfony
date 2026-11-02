<?php

declare(strict_types=1);

namespace App\Portal\EventListener;

use App\Portal\Security\PortalIdentity;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * Refuses a deactivated account right after the password check (priority 0): only someone who knows the
 * password learns that the account exists and what state it is in. An account with no password never gets
 * here, the check answers it as any wrong password.
 */
#[AsEventListener(event: CheckPassportEvent::class, priority: -1)]
final class RefusePortalLoginListener
{
    public function __invoke(CheckPassportEvent $event): void
    {
        $user = $event->getPassport()->getUser();
        if (!$user instanceof PortalIdentity) {
            return;
        }

        if (!$user->isActive()) {
            throw new CustomUserMessageAccountStatusException('login.error.disabled');
        }
    }
}
