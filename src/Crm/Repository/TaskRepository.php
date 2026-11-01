<?php

declare(strict_types=1);

namespace App\Crm\Repository;

use App\Crm\Entity\Task;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Task>
 */
class TaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Task::class);
    }

    /**
     * Loads the task under `SELECT … FOR UPDATE` (inside the bus transaction), or null when it is gone.
     */
    public function lock(string $id): ?Task
    {
        return Uuid::isValid($id) ? $this->find($id, LockMode::PESSIMISTIC_WRITE) : null;
    }

    /**
     * The one renewal task of a deal, done or not (`uk_crm_tasks_renewal`).
     */
    public function renewalOf(string $dealId): ?Task
    {
        return $this->findOneBy(['deal' => $dealId, 'isRenewal' => true]);
    }
}
