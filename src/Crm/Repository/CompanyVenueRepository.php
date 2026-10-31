<?php

declare(strict_types=1);

namespace App\Crm\Repository;

use App\Crm\Entity\CompanyVenue;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

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
