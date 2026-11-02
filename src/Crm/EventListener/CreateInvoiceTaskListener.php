<?php

declare(strict_types=1);

namespace App\Crm\EventListener;

use App\Crm\Command\CreateCompanyForVenue;
use App\Crm\Command\CreateInvoiceTask;
use App\Crm\ReadModel\VenueCompanyQuery;
use App\Shared\Uid\EntityId;
use App\Venues\Event\NewVenuePublished;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A new venue's first publish reminds the owner to invoice it (POR-14, Q20), whoever created it and wherever it went
 * online. Nothing that goes wrong may reach the publish: it stands, and the failure is logged, with no retry. The task
 * hangs on the venue's company, and a venue made in the CMS usually has none, so one is created first, as for a
 * confirmed manager; the check comes first because `CreateCompanyForVenue` leaves an audit row even when it changes
 * nothing (P1). The log names the class, never the message: a driver message can quote an address.
 */
#[AsEventListener(event: NewVenuePublished::class)]
final class CreateInvoiceTaskListener
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly VenueCompanyQuery $companies,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(NewVenuePublished $event): void
    {
        try {
            $companyId = $this->companies->companyIdOf($event->venueId);
            if (null === $companyId) {
                $companyId = EntityId::generate();
                $this->commandBus->dispatch(new CreateCompanyForVenue($event->venueId, $companyId));
            }

            $this->commandBus->dispatch(new CreateInvoiceTask(EntityId::generate(), $companyId));
        } catch (\Throwable $failure) {
            $exception = $failure instanceof HandlerFailedException ? (array_values($failure->getWrappedExceptions())[0] ?? $failure) : $failure;
            // The values go into placeholders: the default logger prints nothing else of the context.
            $this->logger->error('Venue {venue}: writing the invoice task failed ({exception_class} at {file}:{line}).', [
                'venue' => $event->venueId,
                'exception_class' => $exception::class,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);
        }
    }
}
