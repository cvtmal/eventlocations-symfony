<?php

declare(strict_types=1);

namespace App\Admin\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Lets Lighthouse and the Nu Html Checker reach logged-in pages (`make lighthouse URL=/admin ADMIN=1`).
 * Inert unless APP_ENV is `dev`, the token is set and the X-Admin-Dev-Login header matches it. Its token
 * is a PostAuthenticationToken, which scheb does not challenge (scheb_2fa.yaml `security_tokens`).
 */
final class DevAutoLoginAuthenticator extends AbstractAuthenticator
{
    public const string HEADER = 'X-Admin-Dev-Login';

    public function __construct(
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        #[Autowire(env: 'ADMIN_DEV_AUTOLOGIN')]
        private readonly string $token,
        #[Autowire(env: 'ADMIN_DEV_AUTOLOGIN_EMAIL')]
        private readonly string $email,
    ) {
    }

    public function supports(Request $request): bool
    {
        return 'dev' === $this->environment
            && '' !== $this->token
            && hash_equals($this->token, (string) $request->headers->get(self::HEADER));
    }

    public function authenticate(Request $request): Passport
    {
        return new SelfValidatingPassport(new UserBadge($this->email));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return null;
    }
}
