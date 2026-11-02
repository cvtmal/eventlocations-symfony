<?php

declare(strict_types=1);

namespace App\Portal\Event;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Publishes `LocationManagerConfirmed` and `LocationSubmitted` so the CRM can subscribe without Portal knowing it. As
 * with `InquiryEvents`, a handler cannot run code after its own `doctrine_transaction` commit, so the controller
 * calls this after the dispatch. Nothing a subscriber does may fail the request: everything is caught and logged, and
 * the catch is per dispatch, so a throwing subscriber does stop later subscribers of the same event.
 */
final readonly class PortalEvents
{
    public function __construct(
        private EventDispatcherInterface $dispatcher,
        private LoggerInterface $logger,
    ) {
    }

    public function managerConfirmed(LocationManagerConfirmed $event): void
    {
        try {
            $this->dispatcher->dispatch($event);
        } catch (\Throwable $exception) {
            // The values go into placeholders: the default logger prints nothing else of the context.
            $this->logger->error('Venue {venue}: a LocationManagerConfirmed subscriber failed ({exception_class} at {file}:{line}).', [
                'venue' => $event->venueId,
                'exception_class' => $exception::class,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);
        }
    }

    public function locationSubmitted(LocationSubmitted $event): void
    {
        try {
            $this->dispatcher->dispatch($event);
        } catch (\Throwable $exception) {
            // The values go into placeholders: the default logger prints nothing else of the context.
            $this->logger->error('Venue {venue}: a LocationSubmitted subscriber failed ({exception_class} at {file}:{line}).', [
                'venue' => $event->venueId,
                'exception_class' => $exception::class,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);
        }
    }
}
