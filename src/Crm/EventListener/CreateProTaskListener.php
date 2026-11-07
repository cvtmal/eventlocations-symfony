<?php

declare(strict_types=1);

namespace App\Crm\EventListener;

use App\Crm\Command\CreateCompanyForVenue;
use App\Crm\Command\CreateProRequestTask;
use App\Crm\ProRequestTitle;
use App\Crm\ReadModel\VenueCompanyQuery;
use App\Portal\Event\ProRequested;
use App\Shared\Uid\EntityId;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A manager's "Pro anfragen" reminds staff to get in touch and invoice (F12), and nothing that goes wrong may reach the
 * manager: the request stands, the failure is logged, with no retry. The task hangs on the venue's company, and a venue
 * made in the CMS usually has none, so one is created first, as for the invoice task; both checks come first because a
 * command that changes nothing still leaves an audit row (P1). The log names the venue and the class, never the
 * message: a driver message can quote the address.
 */
#[AsEventListener(event: ProRequested::class)]
final class CreateProTaskListener
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly VenueCompanyQuery $companies,
        private readonly ProRequestTitle $titles,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProRequested $event): void
    {
        try {
            $companyId = $this->companies->companyIdOf($event->venueId);
            if (null === $companyId) {
                $companyId = EntityId::generate();
                $this->commandBus->dispatch(new CreateCompanyForVenue($event->venueId, $companyId));
            } elseif ($this->companies->hasOpenTask($companyId, $this->titles->of($event->venueName, $event->email))) {
                return;
            }

            $this->commandBus->dispatch(new CreateProRequestTask(EntityId::generate(), $companyId, $event->venueName, $event->email));
        } catch (\Throwable $failure) {
            $exception = $failure instanceof HandlerFailedException ? (array_values($failure->getWrappedExceptions())[0] ?? $failure) : $failure;
            // The values go into placeholders: the default logger prints nothing else of the context.
            $this->logger->error('Venue {venue}: writing the Pro request task failed ({exception_class} at {file}:{line}).', [
                'venue' => $event->venueId,
                'exception_class' => $exception::class,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);
        }
    }
}
