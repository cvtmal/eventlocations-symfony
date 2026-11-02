<?php

declare(strict_types=1);

namespace App\Portal\EventListener;

use App\Portal\Security\PortalArea;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * S10: nothing under /konto is cached or indexed, error responses included, as under /admin. The priority
 * puts it after the session listener (-1000), which would otherwise overwrite Cache-Control and which
 * writes the session cookie with the static path /admin. That cookie is moved to /konto here, whether it
 * sets a session or deletes one (a stale cookie on an empty session). Logout is the former: the CSRF check
 * writes into the fresh session, so it leaves a new KONTOSESSID without a login, as on /admin.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -1010)]
final class PortalResponseHeadersListener
{
    public function __construct(
        private readonly PortalArea $portalArea,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->portalArea->contains($event->getRequest())) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $headers->set('Cache-Control', 'no-store, private');
        $headers->set('X-Robots-Tag', 'noindex, nofollow');

        foreach ($headers->getCookies() as $cookie) {
            if (PortalArea::SESSION_COOKIE !== $cookie->getName() || PortalArea::COOKIE_PATH === $cookie->getPath()) {
                continue;
            }

            $headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
            $headers->setCookie($cookie->withPath(PortalArea::COOKIE_PATH));
        }
    }
}
