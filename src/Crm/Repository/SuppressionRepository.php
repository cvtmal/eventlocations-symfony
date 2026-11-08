<?php

declare(strict_types=1);

namespace App\Crm\Repository;

use App\Crm\Entity\Suppression;
use App\Crm\ValueObject\SuppressionReason;
use App\Shared\Uid\EntityId;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The write side of the suppression list (G11). Both writes insert with `ON CONFLICT DO NOTHING`, so a second opt-out,
 * a double click or two requests at once leave the first row and its reason as they are, and never fail.
 *
 * @extends ServiceEntityRepository<Suppression>
 */
class SuppressionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Suppression::class);
    }

    /**
     * @return bool whether the Location was not on the list before
     */
    public function addCompany(string $companyId, SuppressionReason $reason, \DateTimeImmutable $at): bool
    {
        return 1 === $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO suppressions (id, company_id, reason, created_at, updated_at) VALUES (:id, :company, :reason, :at, :at) ON CONFLICT DO NOTHING',
            ['id' => EntityId::generate(), 'company' => $companyId, 'reason' => $reason->value, 'at' => $at->format('Y-m-d H:i:sP')],
        );
    }

    /**
     * @param string $email a canonical (lower-case) address
     *
     * @return bool whether the address was not on the list before
     */
    public function addEmail(string $email, SuppressionReason $reason, \DateTimeImmutable $at): bool
    {
        return 1 === $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO suppressions (id, email, reason, created_at, updated_at) VALUES (:id, :email, :reason, :at, :at) ON CONFLICT DO NOTHING',
            ['id' => EntityId::generate(), 'email' => $email, 'reason' => $reason->value, 'at' => $at->format('Y-m-d H:i:sP')],
            ['email' => ParameterType::STRING],
        );
    }

    /**
     * Every address the Location holds today: its own and its contacts', lower-case and without duplicates.
     *
     * @return list<string>
     */
    public function addressesOf(string $companyId): array
    {
        /** @var list<string> $addresses */
        $addresses = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT email FROM crm_companies WHERE id = :id AND email IS NOT NULL
             UNION SELECT email FROM crm_contacts WHERE company_id = :id AND email IS NOT NULL',
            ['id' => $companyId],
        );

        return array_values(array_unique(array_map('mb_strtolower', $addresses)));
    }

    public function companySuppressed(string $companyId): bool
    {
        return false !== $this->getEntityManager()->getConnection()->fetchOne('SELECT 1 FROM suppressions WHERE company_id = :id', ['id' => $companyId]);
    }
}
