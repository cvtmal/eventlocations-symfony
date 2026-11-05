<?php

declare(strict_types=1);

namespace App\Crm\Repository;

use App\Crm\Entity\Company;
use App\Crm\Exception\CompanyNotFound;
use App\Crm\Exception\VenueNotLinked;
use App\Venues\Entity\Venue;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Company>
 */
class CompanyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Company::class);
    }

    /**
     * Loads the company under `SELECT … FOR UPDATE` (inside the bus transaction).
     *
     * @throws CompanyNotFound
     */
    public function lock(string $id): Company
    {
        $entity = Uuid::isValid($id) ? $this->find($id, LockMode::PESSIMISTIC_WRITE) : null;

        return $entity ?? throw CompanyNotFound::forId($id);
    }

    /**
     * @throws CompanyNotFound
     */
    public function get(string $id): Company
    {
        $entity = Uuid::isValid($id) ? $this->find($id) : null;

        return $entity ?? throw CompanyNotFound::forId($id);
    }

    /**
     * The Location whose Eintrag this venue is (#56), or null.
     */
    public function forVenue(string $venueId): ?Company
    {
        return Uuid::isValid($venueId) ? $this->findOneBy(['venue' => $venueId]) : null;
    }

    /**
     * The venue, when it is the company's Eintrag: a deal only takes such a venue (CRM-7). The
     * handler has locked the company already, so this reads it from the identity map.
     *
     * @throws VenueNotLinked
     * @throws CompanyNotFound
     */
    public function venueOf(string $companyId, string $venueId): Venue
    {
        $venue = $this->get($companyId)->venue();

        return null !== $venue && $venue->id() === $venueId ? $venue : throw VenueNotLinked::toCompany($venueId, $companyId);
    }

    /**
     * Serialises every write that assigns this venue (LinkVenue, CreateCompanyForVenue), so the
     * "already assigned" check and the write cannot interleave. Held until the bus transaction ends.
     * The key text predates #56; it is only a namespace.
     */
    public function lockVenue(string $venueId): void
    {
        $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
            ['crm_company_venues:'.$venueId],
        );
    }

    /**
     * Deletes the row with SQL and lets the database cascade (contacts, timeline links and tasks
     * CASCADE; #55); the Eintrag is freed with the row (#56). Not `remove()`: a managed task or contact that still points
     * at a removed entity would fail the next flush.
     */
    public function delete(string $id): void
    {
        $this->getEntityManager()->getConnection()->executeStatement('DELETE FROM crm_companies WHERE id = ?', [$id]);
    }

    /**
     * Serialises the customer imports of one folded name (#48), so the "name exists" check and the
     * insert cannot interleave. Held until the bus transaction ends.
     */
    public function lockFoldedName(string $name): void
    {
        $this->getEntityManager()->getConnection()->executeQuery(
            "SELECT pg_advisory_xact_lock(hashtextextended('crm_customer_import:' || geo_fold_de(?), 0))",
            [$name],
        );
    }

    /**
     * Whether a company of any origin has this name under `geo_fold_de()`, the key the import matches on.
     */
    public function foldedNameExists(string $name): bool
    {
        return false !== $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT 1 FROM crm_companies WHERE geo_fold_de(name) = geo_fold_de(?) LIMIT 1',
            [$name],
        );
    }
}
