<?php

declare(strict_types=1);

namespace App\Admin\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * scheb's failure handler of the admin firewall. It counts each wrong code on the `admin_totp` limiter
 * (5 per 15 minutes per user, S12) and, once the limit is reached, ends the half-finished login: password
 * and code then both have to be repeated, and TotpThrottleListener refuses the code until the window ends.
 * Below the limit it does what scheb's default handler does.
 */
final class TotpFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function __construct(
        private readonly RateLimiterFactoryInterface $adminTotpLimiter,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($exception instanceof TooManyLoginAttemptsAuthenticationException || $this->consumeAttempt()) {
            return $this->endLogin($request);
        }

        $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);

        return new RedirectResponse($this->urls->generate('admin_2fa_login'));
    }

    /**
     * @return bool true when this attempt used up the limit
     */
    private function consumeAttempt(): bool
    {
        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof AdminIdentity) {
            return false;
        }

        return 0 === $this->adminTotpLimiter->create($user->id())->consume()->getRemainingTokens();
    }

    private function endLogin(Request $request): Response
    {
        $this->tokenStorage->setToken(null);
        $session = $request->getSession();
        $session->invalidate();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', 'login.error.throttled');
        }

        return new RedirectResponse($this->urls->generate('admin_login'));
    }
}
