<?php

declare(strict_types=1);

namespace App\Admin\EventListener;

use App\Admin\Security\AdminArea;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The 2-hour idle timeout (S12). It runs after the session listener (128) and before the firewall (8), so
 * the firewall finds an emptied session and the entry point sends the user to the login page.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 16)]
final class IdleTimeoutListener
{
    public const int IDLE_SECONDS = 7200;

    private const string LAST_SEEN = 'admin.last_seen';

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly AdminArea $adminArea,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->adminArea->contains($request) || !$request->hasPreviousSession()) {
            return;
        }

        $session = $request->getSession();
        $now = $this->clock->now()->getTimestamp();
        $lastSeen = $session->get(self::LAST_SEEN);

        if (\is_int($lastSeen) && $now - $lastSeen > self::IDLE_SECONDS) {
            $session->invalidate();
            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add('error', 'flash.session_expired');
            }
        }

        $session->set(self::LAST_SEEN, $now);
    }
}
