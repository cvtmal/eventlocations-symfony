<?php

declare(strict_types=1);

namespace App\Crm\Repository;

use App\Crm\Entity\InquiryLink;
use App\Crm\Exception\InquiryNotFound;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<InquiryLink>
 */
class InquiryLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InquiryLink::class);
    }

    public function has(string $inquiryId): bool
    {
        return $this->count(['inquiry' => $inquiryId]) > 0;
    }

    /**
     * Loads the link under `SELECT … FOR UPDATE` (inside the bus transaction).
     *
     * @throws InquiryNotFound
     */
    public function lock(string $id): InquiryLink
    {
        $entity = Uuid::isValid($id) ? $this->find($id, LockMode::PESSIMISTIC_WRITE) : null;

        return $entity ?? throw InquiryNotFound::forId($id);
    }
}
