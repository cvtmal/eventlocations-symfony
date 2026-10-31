<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\LinkVenue;
use App\Crm\Entity\CompanyVenue;
use App\Crm\Exception\VenueAlreadyLinked;
use App\Crm\Exception\VenueNotFound;
use App\Crm\Repository\CompanyRepository;
use App\Crm\Repository\CompanyVenueRepository;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Entity\Venue;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Takes the venue's advisory lock (shared with CreateCompanyForVenue) before it looks for a link,
 * so two writers cannot both see the venue free. The unique key still decides, mapped to
 * VenueAlreadyLinked by flushing here.
 */
final class LinkVenueHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly CompanyRepository $companies,
        private readonly CompanyVenueRepository $links,
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

        $this->links->lockVenue($venue->id());
        $link = $this->links->forVenue($venue->id());
        if (null !== $link) {
            if ($link->company()->id() === $company->id()) {
                return;
            }

            throw VenueAlreadyLinked::forVenue($venue->id());
        }

        $this->em->persist(new CompanyVenue($company, $venue));

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            throw VenueAlreadyLinked::forVenue($venue->id());
        }
    }
}
