<?php

declare(strict_types=1);

namespace App\Portal\Repository;

use App\Portal\Entity\PortalVenueAccess;
use App\Portal\ValueObject\PortalVenueAccessStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PortalVenueAccess>
 */
class PortalVenueAccessRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PortalVenueAccess::class);
    }

    public function findAccess(string $portalUserId, string $venueId): ?PortalVenueAccess
    {
        return $this->find(['user' => $portalUserId, 'venue' => $venueId]);
    }

    /**
     * Raw SQL, because two parallel clicks by one manager must not meet a unique violation: the second one
     * writes nothing and raises nothing, and an existing row of any status stays as it is. True when this call
     * wrote the row, so only one of the two clicks tells staff.
     */
    public function insertPending(string $portalUserId, string $venueId, \DateTimeImmutable $at): bool
    {
        return 0 < $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO portal_venue_access (portal_user_id, venue_id, status, requested_at)
                VALUES (:user, :venue, :status, :at)
                ON CONFLICT (portal_user_id, venue_id) DO NOTHING
                SQL,
            ['user' => $portalUserId, 'venue' => $venueId, 'status' => PortalVenueAccessStatus::Pending->value, 'at' => $at],
            ['at' => Types::DATETIMETZ_IMMUTABLE],
        );
    }
}
