<?php

declare(strict_types=1);

namespace App\Portal\Repository;

use App\Portal\Entity\Submission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Submission>
 */
class SubmissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Submission::class);
    }

    public function ofVenue(string $venueId): ?Submission
    {
        return $this->find($venueId);
    }

    /**
     * Counted through `portal_venue_access`: a draft has no `submitted_by`.
     */
    public function countUnfinished(string $portalUserId): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM portal_submissions s
                JOIN portal_venue_access a ON a.venue_id = s.venue_id
                JOIN venues v ON v.id = s.venue_id
                WHERE a.portal_user_id = :id AND a.status = 'active'
                  AND s.status IN ('draft', 'submitted', 'rejected') AND v.status = 'unpublished'
                SQL,
            ['id' => $portalUserId],
        );
    }
}
