<?php

declare(strict_types=1);

namespace App\Shared\EventListener;

use App\Shared\Security\ReviewMode;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * review-demo: no page of the demo may be indexed, whatever the route says. After every other listener that sets
 * X-Robots-Tag (the back office's and /konto's run at -1010). The review stack's edge nginx adds the same header to the
 * files nginx serves itself (docker/review/edge.conf).
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -1020)]
final readonly class ReviewModeHeadersListener
{
    public function __construct(
        private ReviewMode $reviewMode,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if ($this->reviewMode->isOn()) {
            $event->getResponse()->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }
    }
}
