<?php

declare(strict_types=1);

namespace App\Crm\Repository;

use App\Crm\Entity\Company;
use App\Crm\Exception\CompanyNotFound;
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
     * Deletes the row with SQL and lets the database cascade (contacts SET NULL; venue links,
     * timeline links and tasks CASCADE). Not `remove()`: a managed task or contact that still points
     * at a removed entity would fail the next flush.
     */
    public function delete(string $id): void
    {
        $this->getEntityManager()->getConnection()->executeStatement('DELETE FROM crm_companies WHERE id = ?', [$id]);
    }
}
