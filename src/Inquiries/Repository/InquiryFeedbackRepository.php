<?php

declare(strict_types=1);

namespace App\Inquiries\Repository;

use App\Inquiries\Entity\InquiryFeedback;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<InquiryFeedback>
 */
class InquiryFeedbackRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InquiryFeedback::class);
    }

    public function add(InquiryFeedback $feedback): void
    {
        $this->getEntityManager()->persist($feedback);
    }

    public function has(string $inquiryId): bool
    {
        return $this->count(['inquiry' => $inquiryId]) > 0;
    }

    /**
     * The row under `SELECT … FOR UPDATE` (inside the bus transaction), or null.
     */
    public function lock(string $inquiryId): ?InquiryFeedback
    {
        return Uuid::isValid($inquiryId) ? $this->find($inquiryId, LockMode::PESSIMISTIC_WRITE) : null;
    }
}
