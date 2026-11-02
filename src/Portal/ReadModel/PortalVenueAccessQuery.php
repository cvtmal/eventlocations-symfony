<?php

declare(strict_types=1);

namespace App\Portal\ReadModel;

use Doctrine\DBAL\Connection;

/**
 * Whether a manager may work on a venue. Only an `active` row counts: `pending` is a request staff has not
 * confirmed yet.
 */
final class PortalVenueAccessQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function isActive(string $portalUserId, int $venueNumber): bool
    {
        return false !== $this->connection->fetchOne(
            <<<'SQL'
                SELECT 1
                FROM portal_venue_access a
                JOIN venues v ON v.id = a.venue_id
                WHERE a.portal_user_id = :user AND v.number = :number AND a.status = 'active'
                SQL,
            ['user' => $portalUserId, 'number' => $venueNumber],
        );
    }

    /**
     * @return list<int>
     */
    public function activeNumbers(string $portalUserId): array
    {
        return array_map(intval(...), $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT v.number
                FROM portal_venue_access a
                JOIN venues v ON v.id = a.venue_id
                WHERE a.portal_user_id = :id AND a.status = 'active'
                SQL,
            ['id' => $portalUserId],
        ));
    }
}
