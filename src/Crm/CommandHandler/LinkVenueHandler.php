<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\LinkVenue;
use App\Crm\Exception\CompanyHasVenue;
use App\Crm\Exception\VenueAlreadyLinked;
use App\Crm\Exception\VenueNotFound;
use App\Crm\Repository\CompanyRepository;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Entity\Venue;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Takes the venue's advisory lock (shared with CreateCompanyForVenue) before it looks for the venue's
 * Location, so two writers cannot both see the venue free. The unique key `uk_crm_companies_venue_id`
 * still decides, mapped to VenueAlreadyLinked by flushing here. A Location that has another Eintrag
 * is refused with CompanyHasVenue (#56): release it first.
 */
final class LinkVenueHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly CompanyRepository $companies,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(LinkVenue $command): void
    {
        $company = $this->companies->lock($command->companyId);
        $venue = Uuid::isValid($command->venueId) ? $this->em->find(Venue::class, $command->venueId) : null;
        if (null === $venue) {
            throw VenueNotFound::forId($command->venueId);
        }

        $this->companies->lockVenue($venue->id());
        $current = $company->venue();
        if (null !== $current) {
            if ($current->id() === $venue->id()) {
                return;
            }

            throw CompanyHasVenue::forCompany($company->id());
        }

        if (null !== $this->companies->forVenue($venue->id())) {
            throw VenueAlreadyLinked::forVenue($venue->id());
        }

        $company->assignVenue($venue);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            throw VenueAlreadyLinked::forVenue($venue->id());
        }
    }
}
