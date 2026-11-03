<?php

declare(strict_types=1);

namespace App\Admin\EventListener;

use App\Admin\Security\AdminArea;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Gives a fully logged-in back-office session a 30-day cookie that slides with every response. Symfony sends the
 * session cookie only when the id changes (AbstractSessionListener), so a persistent cookie from
 * `cookie_lifetime` would expire 30 days after the login however active the user is; this re-sends it each time.
 * `framework.session.cookie_lifetime` stays 0, so KONTOSESSID, anonymous and mid-2FA sessions keep a
 * browser-session cookie.
 *
 * The expiry uses time(), not the clock: browsers and BrowserKit judge it by real time, as Symfony's session
 * listener does. The idle decision itself stays on the clock (IdleTimeoutListener).
 *
 * markLoggedIn runs at -990, between the context listener (0), which has the final token, and the session save
 * (-1000), so the session is still open. extendCookie runs at -1010, after the session listener wrote or skipped
 * the cookie, like AdminResponseHeadersListener, and reads only the request attribute and the session id.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, method: 'markLoggedIn', priority: -990)]
#[AsEventListener(event: KernelEvents::RESPONSE, method: 'extendCookie', priority: -1010)]
final class AdminSessionCookieListener
{
    private const string LOGGED_IN = '_admin_session_logged_in';

    /**
     * @param array<string, mixed> $sessionOptions
     */
    public function __construct(
        private readonly AdminArea $adminArea,
        private readonly Security $security,
        #[Autowire(param: 'session.storage.options')]
        private readonly array $sessionOptions,
    ) {
    }

    public function markLoggedIn(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->adminArea->contains($request) || !$request->hasSession() || !$request->getSession()->isStarted()) {
            return;
        }

        $token = $this->security->getToken();
        if (null !== $token && !$token instanceof TwoFactorTokenInterface) {
            $request->attributes->set(self::LOGGED_IN, true);
        }
    }

    public function extendCookie(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->adminArea->contains($request) || true !== $request->attributes->get(self::LOGGED_IN) || !$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        $name = $session->getName();
        $id = $session->getId();
        if ('' === $id) {
            return;
        }

        $path = $this->option('cookie_path') ?? '/';
        $expires = time() + IdleTimeoutListener::IDLE_SECONDS;
        $headers = $event->getResponse()->headers;

        foreach ($headers->getCookies() as $cookie) {
            if ($name !== $cookie->getName() || $path !== $cookie->getPath()) {
                continue;
            }
            if (!$cookie->isCleared()) {
                // A new id (login, 2FA completion, migrate): the session listener's cookie, made persistent.
                $headers->setCookie($cookie->withExpires($expires));
            }

            return;
        }

        if ($request->cookies->get($name) !== $id) {
            return;
        }

        // The same id as the request's: the session listener sent nothing, so the cookie is sent again.
        $secure = $this->sessionOptions['cookie_secure'] ?? false;
        $headers->setCookie(Cookie::create(
            $name,
            $id,
            $expires,
            $path,
            $this->option('cookie_domain'),
            'auto' === $secure ? $request->isSecure() : (bool) $secure,
            (bool) ($this->sessionOptions['cookie_httponly'] ?? true),
            false,
            $this->sameSite(),
        ));
    }

    /**
     * @return Cookie::SAMESITE_*
     */
    private function sameSite(): string
    {
        return match ($this->option('cookie_samesite')) {
            Cookie::SAMESITE_STRICT => Cookie::SAMESITE_STRICT,
            Cookie::SAMESITE_NONE => Cookie::SAMESITE_NONE,
            default => Cookie::SAMESITE_LAX,
        };
    }

    private function option(string $key): ?string
    {
        $value = $this->sessionOptions[$key] ?? null;

        return \is_string($value) ? $value : null;
    }
}
