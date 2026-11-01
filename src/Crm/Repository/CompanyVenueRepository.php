<?php

declare(strict_types=1);

namespace App\Crm\Repository;

use App\Crm\Entity\CompanyVenue;
use App\Crm\Exception\VenueNotLinked;
use App\Venues\Entity\Venue;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<CompanyVenue>
 */
class CompanyVenueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompanyVenue::class);
    }

    public function forVenue(string $venueId): ?CompanyVenue
    {
        return $this->findOneBy(['venue' => $venueId]);
    }

    /**
     * The venue, when it is one of the company's: a deal only takes such a venue (CRM-7).
     *
     * @throws VenueNotLinked
     */
    public function venueOf(string $companyId, string $venueId): Venue
    {
        $link = Uuid::isValid($venueId) ? $this->forVenue($venueId) : null;

        return $link?->company()->id() === $companyId ? $link->venue() : throw VenueNotLinked::toCompany($venueId, $companyId);
    }

    /**
     * Serialises every write that links this venue (LinkVenue, CreateCompanyForVenue), so the
     * "already linked" check and the insert cannot interleave. Held until the bus transaction ends.
     */
    public function lockVenue(string $venueId): void
    {
        $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
            ['crm_company_venues:'.$venueId],
        );
    }
}
