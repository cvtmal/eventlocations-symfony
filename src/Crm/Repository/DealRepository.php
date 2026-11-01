<?php

declare(strict_types=1);

namespace App\Crm\Repository;

use App\Crm\Entity\Deal;
use App\Crm\Entity\DealContact;
use App\Crm\Exception\DealNotFound;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Deal>
 */
class DealRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Deal::class);
    }

    /**
     * Loads the deal under `SELECT … FOR UPDATE` (inside the bus transaction). The lock order is company, deal,
     * venue: a handler reads `companyIdOf()`, locks the company, then calls this (D11).
     *
     * @throws DealNotFound
     */
    public function lock(string $id): Deal
    {
        $deal = Uuid::isValid($id) ? $this->find($id, LockMode::PESSIMISTIC_WRITE) : null;

        return $deal ?? throw DealNotFound::forId($id);
    }

    /**
     * The company of a deal, read without a lock, so a handler can take the company's lock first. It never
     * changes, so the answer cannot go stale.
     *
     * @throws DealNotFound
     */
    public function companyIdOf(string $id): string
    {
        $companyId = Uuid::isValid($id)
            ? $this->getEntityManager()->getConnection()->fetchOne('SELECT company_id FROM crm_deals WHERE id = ?', [$id])
            : false;

        return false === $companyId ? throw DealNotFound::forId($id) : (string) $companyId;
    }

    public function hasWonDeal(string $companyId): bool
    {
        return (bool) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM crm_deals d JOIN crm_deal_stages s ON s.code = d.stage_code WHERE d.company_id = ? AND s.is_won)',
            [$companyId],
        );
    }

    public function existsForCompany(string $companyId): bool
    {
        return (bool) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM crm_deals WHERE company_id = ?)',
            [$companyId],
        );
    }

    public function isRenewed(string $id): bool
    {
        return (bool) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM crm_deals WHERE renewed_from_id = ?)',
            [$id],
        );
    }

    /**
     * @return list<DealContact>
     */
    public function contactLinksOf(string $dealId): array
    {
        return $this->getEntityManager()->getRepository(DealContact::class)->findBy(['deal' => $dealId]);
    }

    public function hasContact(string $dealId, string $contactId): bool
    {
        return (bool) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM crm_deal_contacts WHERE deal_id = ? AND contact_id = ?)',
            [$dealId, $contactId],
        );
    }

    public function add(Deal $deal): void
    {
        $this->getEntityManager()->persist($deal);
    }

    /**
     * Deletes the row with SQL and lets the database cascade (contact links, timeline links, tasks; a renewal
     * keeps standing, SET NULL). Not `remove()`: a managed task that still points at a removed deal would fail the
     * next flush.
     */
    public function delete(string $id): void
    {
        $this->getEntityManager()->getConnection()->executeStatement('DELETE FROM crm_deals WHERE id = ?', [$id]);
    }

    public function unlinkContact(string $dealId, string $contactId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM crm_deal_contacts WHERE deal_id = ? AND contact_id = ?',
            [$dealId, $contactId],
        );
    }
}
