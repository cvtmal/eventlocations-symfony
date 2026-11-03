<?php

declare(strict_types=1);

namespace App\Portal\EventListener;

use App\Portal\Security\PortalArea;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Ends a manager's session after 2 hours without a request to /konto. The session store keeps every file 30 days
 * for the back office, and neither `gc_maxlifetime` nor the nightly sweep can be split by area, so the 7200 s that
 * /konto had through `gc_maxlifetime` live here now. Only a session holding the portal token is timed, so a stale or
 * anonymous cookie is handled as before. It runs after the session listener (128) and before the firewall (8), as
 * Admin's IdleTimeoutListener does; it is a copy rather than a shared class because Shared may not know either area.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 16)]
final class PortalIdleTimeoutListener
{
    public const int IDLE_SECONDS = 7200;

    private const string LAST_SEEN = 'portal.last_seen';

    private const string TOKEN = '_security_'.PortalArea::FIREWALL;

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly PortalArea $portalArea,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->portalArea->contains($request) || !$request->hasPreviousSession()) {
            return;
        }

        $session = $request->getSession();
        if (!$session->has(self::TOKEN)) {
            return;
        }

        $now = $this->clock->now()->getTimestamp();
        $lastSeen = $session->get(self::LAST_SEEN);

        if (\is_int($lastSeen) && $now - $lastSeen > self::IDLE_SECONDS) {
            $session->invalidate();

            return;
        }

        $session->set(self::LAST_SEEN, $now);
    }
}
