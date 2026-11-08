<?php

declare(strict_types=1);

namespace App\Venues\Event;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Publishes `NewVenuePublished` so the CRM can subscribe without Venues knowing it, and `LastMinuteOfferExpired` (G14) so
 * Portal can mail the managers. As with `InquiryEvents`, a handler
 * cannot run code after its own `doctrine_transaction` commit, so whoever dispatched `PublishVenue` calls this when the
 * handler answered true: the CMS's publish and the portal's accept. Nothing a subscriber does may fail the publish:
 * everything is caught and logged.
 */
final readonly class VenueEvents
{
    public function __construct(
        private EventDispatcherInterface $dispatcher,
        private LoggerInterface $logger,
    ) {
    }

    public function newVenuePublished(string $venueId): void
    {
        try {
            $this->dispatcher->dispatch(new NewVenuePublished($venueId));
        } catch (\Throwable $exception) {
            // The values go into placeholders: the default logger prints nothing else of the context.
            $this->logger->error('Venue {venue}: a NewVenuePublished subscriber failed ({exception_class} at {file}:{line}).', [
                'venue' => $venueId,
                'exception_class' => $exception::class,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);
        }
    }

    /**
     * Called by `app:last-minute:expire` once the delete has committed, one call per deleted offer.
     */
    public function lastMinuteOfferExpired(LastMinuteOfferExpired $event): void
    {
        try {
            $this->dispatcher->dispatch($event);
        } catch (\Throwable $exception) {
            $this->logger->error('Venue {venue}: a LastMinuteOfferExpired subscriber failed ({exception_class} at {file}:{line}).', [
                'venue' => $event->venueId,
                'exception_class' => $exception::class,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);
        }
    }
}
