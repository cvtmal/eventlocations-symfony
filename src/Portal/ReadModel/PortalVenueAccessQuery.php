<?php

declare(strict_types=1);

namespace App\Portal\ReadModel;

use App\Venues\VenueClaims;
use Doctrine\DBAL\Connection;

/**
 * Whether a manager may work on a venue. Only an `active` row counts: `pending` is a request staff has not
 * confirmed yet. A venue with a competing claim is frozen (G6): a claim that found somebody else's row on the venue
 * waits for staff (`competing`), and nobody edits until they decide. A plain access request (#43) freezes nothing.
 */
final class PortalVenueAccessQuery implements VenueClaims
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
                  AND NOT EXISTS (SELECT 1 FROM portal_venue_access p WHERE p.venue_id = a.venue_id AND p.status = 'pending' AND p.competing)
                SQL,
            ['user' => $portalUserId, 'number' => $venueNumber],
        );
    }

    /**
     * D18: an entry is confirmed by its operator once an active account holds active access to it (a frozen venue too).
     */
    public function isConfirmed(string $venueId): bool
    {
        return (bool) $this->connection->fetchOne(
            <<<'SQL'
                SELECT EXISTS (
                    SELECT 1 FROM portal_venue_access a JOIN portal_users u ON u.id = a.portal_user_id
                    WHERE a.venue_id = :id AND a.status = 'active' AND u.is_active
                )
                SQL,
            ['id' => $venueId],
        );
    }

    /**
     * The overview's list in one statement: number => whether a competing claim freezes it (see the class comment).
     *
     * @return array<int, bool>
     */
    public function activeLocations(string $portalUserId): array
    {
        $rows = $this->connection->fetchAllKeyValue(
            <<<'SQL'
                SELECT v.number,
                       EXISTS (SELECT 1 FROM portal_venue_access p WHERE p.venue_id = a.venue_id AND p.status = 'pending' AND p.competing) AS frozen
                FROM portal_venue_access a
                JOIN venues v ON v.id = a.venue_id
                WHERE a.portal_user_id = :id AND a.status = 'active'
                SQL,
            ['id' => $portalUserId],
        );
        $locations = [];
        foreach ($rows as $number => $frozen) {
            $locations[(int) $number] = (bool) $frozen;
        }

        return $locations;
    }

    /**
     * Any row at all, `pending` included: a manager without one has not started and goes to the wizard (#45).
     */
    public function hasAnyRow(string $portalUserId): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM portal_venue_access WHERE portal_user_id = :id)',
            ['id' => $portalUserId],
        );
    }
}
