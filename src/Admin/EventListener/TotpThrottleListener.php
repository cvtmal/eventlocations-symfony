<?php

declare(strict_types=1);

namespace App\Admin\EventListener;

use App\Admin\Security\AdminIdentity;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;

/**
 * Refuses a code once the user's `admin_totp` limit is used up, even the right one: logging in again with
 * the password does not buy new attempts. The exception reaches TotpFailureHandler, which ends the login.
 */
#[AsEventListener(event: TwoFactorAuthenticationEvents::ATTEMPT)]
final class TotpThrottleListener
{
    public function __construct(
        private readonly RateLimiterFactoryInterface $adminTotpLimiter,
    ) {
    }

    public function __invoke(TwoFactorAuthenticationEvent $event): void
    {
        $user = $event->getToken()->getUser();
        if ($user instanceof AdminIdentity && 0 === $this->adminTotpLimiter->create($user->id())->consume(0)->getRemainingTokens()) {
            throw new TooManyLoginAttemptsAuthenticationException();
        }
    }
}
