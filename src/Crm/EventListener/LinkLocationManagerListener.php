<?php

declare(strict_types=1);

namespace App\Crm\EventListener;

use App\Crm\Command\CreateCompanyForVenue;
use App\Crm\Command\LinkLocationManager;
use App\Crm\ReadModel\VenueCompanyQuery;
use App\Portal\Event\LocationManagerConfirmed;
use App\Shared\Uid\EntityId;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A confirmed manager reaches the CRM here, and nothing that goes wrong may reach staff's approve (POR-7): the access
 * stands, the failure is logged. The company is checked first because `CreateCompanyForVenue` leaves an audit row even
 * when it changes nothing (P1). The log names the class, never the message: a driver message can quote an address.
 */
#[AsEventListener(event: LocationManagerConfirmed::class)]
final class LinkLocationManagerListener
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly VenueCompanyQuery $companies,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(LocationManagerConfirmed $event): void
    {
        $this->link($event->venueId, $event->venueName, $event->email, $event->firstName, $event->lastName, $event->phone);
    }

    private function link(string $venueId, string $venueName, string $email, string $firstName, string $lastName, ?string $phone): void
    {
        try {
            if (null === $this->companies->companyIdOf($venueId)) {
                $this->commandBus->dispatch(new CreateCompanyForVenue($venueId, EntityId::generate()));
            }
            $this->commandBus->dispatch(new LinkLocationManager($venueId, $venueName, $email, $firstName, $lastName, $phone));
        } catch (\Throwable $failure) {
            $exception = $failure instanceof HandlerFailedException ? (array_values($failure->getWrappedExceptions())[0] ?? $failure) : $failure;
            // The values go into placeholders: the default logger prints nothing else of the context.
            $this->logger->error('Venue {venue}: linking the location manager to the CRM failed ({exception_class} at {file}:{line}).', [
                'venue' => $venueId,
                'exception_class' => $exception::class,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);
        }
    }
}
