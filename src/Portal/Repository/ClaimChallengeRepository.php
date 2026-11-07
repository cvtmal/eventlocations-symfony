<?php

declare(strict_types=1);

namespace App\Portal\Repository;

use App\Portal\Entity\ClaimChallenge;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ClaimChallenge>
 */
class ClaimChallengeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClaimChallenge::class);
    }

    /**
     * Locked until the transaction ends, so two parallel tries of one code count both.
     */
    public function findForUpdate(string $id): ?ClaimChallenge
    {
        return $this->getEntityManager()->find(ClaimChallenge::class, $id, LockMode::PESSIMISTIC_WRITE);
    }

    public function countForEmailSince(string $email, \DateTimeImmutable $since): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT count(*) FROM claim_challenges WHERE email = :email AND created_at >= :since',
            ['email' => mb_strtolower($email), 'since' => $since],
            ['since' => Types::DATETIMETZ_IMMUTABLE],
        );
    }

    public function countForVenueSince(string $venueId, \DateTimeImmutable $since): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT count(*) FROM claim_challenges WHERE venue_id = :venue AND created_at >= :since',
            ['venue' => $venueId, 'since' => $since],
            ['since' => Types::DATETIMETZ_IMMUTABLE],
        );
    }

    /**
     * Rows only serve the rate limits once their half hour is over, so a day is enough to keep a name and address.
     */
    public function deleteCreatedBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM claim_challenges WHERE created_at < :before',
            ['before' => $before],
            ['before' => Types::DATETIMETZ_IMMUTABLE],
        );
    }
}
