<?php

declare(strict_types=1);

namespace App\Admin\EventListener;

use App\Admin\Security\AdminArea;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * S10, ADM-6: nothing under /admin is cached or indexed, error responses included. The priority puts it
 * after the session listener (-1000), which would otherwise overwrite Cache-Control.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -1010)]
final class AdminResponseHeadersListener
{
    public function __construct(
        private readonly AdminArea $adminArea,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->adminArea->contains($event->getRequest())) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $headers->set('Cache-Control', 'no-store, private');
        $headers->set('X-Robots-Tag', 'noindex, nofollow');
    }
}
