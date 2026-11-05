<?php

declare(strict_types=1);

namespace App\Crm\Repository;

use App\Crm\Entity\Activity;
use App\Crm\Exception\ActivityNotFound;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Activity>
 */
class ActivityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Activity::class);
    }

    /**
     * Loads the entry under `SELECT … FOR UPDATE` (inside the bus transaction), so a concurrent edit or delete
     * cannot change it between the check and the write.
     *
     * @throws ActivityNotFound
     */
    public function lock(string $id): Activity
    {
        $entity = Uuid::isValid($id) ? $this->find($id, LockMode::PESSIMISTIC_WRITE) : null;

        return $entity ?? throw ActivityNotFound::forId($id);
    }

    /**
     * Ids of the activities on the company's timeline.
     *
     * @return list<string>
     */
    public function idsLinkedToCompany(string $companyId): array
    {
        /* @var list<string> */
        return $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT activity_id FROM crm_activity_companies WHERE company_id = ?',
            [$companyId],
        );
    }

    /**
     * Ids of the activities on the deal's timeline.
     *
     * @return list<string>
     */
    public function idsLinkedToDeal(string $dealId): array
    {
        /* @var list<string> */
        return $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT activity_id FROM crm_activity_deals WHERE deal_id = ?',
            [$dealId],
        );
    }

    /**
     * Deletes those of the activities that no object links any more (D16): an entry that is on no
     * timeline would be unreachable. The one place that knows every link table.
     *
     * @param list<string> $ids
     *
     * @return int the number deleted
     */
    public function deleteUnlinked(array $ids): int
    {
        if ([] === $ids) {
            return 0;
        }

        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                DELETE FROM crm_activities a
                WHERE a.id IN (:ids)
                  AND NOT EXISTS (SELECT 1 FROM crm_activity_companies l WHERE l.activity_id = a.id)
                  AND NOT EXISTS (SELECT 1 FROM crm_activity_deals l WHERE l.activity_id = a.id)
                SQL,
            ['ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );
    }
}
