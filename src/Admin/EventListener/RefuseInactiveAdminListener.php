<?php

declare(strict_types=1);

namespace App\Admin\EventListener;

use App\Admin\Security\AdminIdentity;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * Refuses a deactivated account right after the password check (priority 0): only someone who knows the
 * password learns that the account exists and is deactivated. A UserChecker cannot do this: checkPreAuth
 * runs before the password, and scheb stops the event checkPostAuth hangs on for a user with TOTP.
 */
#[AsEventListener(event: CheckPassportEvent::class, priority: -1)]
final class RefuseInactiveAdminListener
{
    public function __invoke(CheckPassportEvent $event): void
    {
        $user = $event->getPassport()->getUser();
        if ($user instanceof AdminIdentity && !$user->isActive()) {
            throw new CustomUserMessageAccountStatusException('login.error.disabled');
        }
    }
}
