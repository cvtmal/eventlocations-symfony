<?php

declare(strict_types=1);

namespace App\Portal\ReadModel;

use App\Portal\ValueObject\PortalVenueAccessStatus;
use Doctrine\DBAL\Connection;

/**
 * The managers panel on a venue (#43): who manages it, and which account an address staff typed belongs to. A
 * pending row is the review queue's, not a manager yet.
 */
final class VenueManagersQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<VenueManagerRow>
     */
    public function forVenue(int $number): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT u.id, u.first_name, u.last_name, u.email
                FROM portal_venue_access a
                JOIN venues v ON v.id = a.venue_id
                JOIN portal_users u ON u.id = a.portal_user_id
                WHERE v.number = :number AND a.status = :active
                ORDER BY u.last_name, u.first_name
                SQL,
            ['number' => $number, 'active' => PortalVenueAccessStatus::Active->value],
        );

        return array_map(
            static fn (array $row): VenueManagerRow => new VenueManagerRow((string) $row['id'], (string) $row['first_name'], (string) $row['last_name'], (string) $row['email']),
            $rows,
        );
    }

    /**
     * Any account counts, unconfirmed and deactivated too: staff name an account that exists.
     */
    public function accountIdByEmail(string $email): ?string
    {
        $id = $this->connection->fetchOne('SELECT id FROM portal_users WHERE email = :email', ['email' => mb_strtolower($email)]);

        return false === $id ? null : (string) $id;
    }
}
