<?php

declare(strict_types=1);

namespace App\Portal\ReadModel;

use App\Portal\ValueObject\PortalVenueAccessStatus;
use Doctrine\DBAL\Connection;

/**
 * The managers panel on a venue (#43): who manages it, whose invitation is still open, and which account an address
 * staff typed belongs to. A pending row is the review queue's, not a manager yet.
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
                SELECT u.id, u.first_name, u.last_name, u.email, u.password_hash IS NULL AS invited
                FROM portal_venue_access a
                JOIN venues v ON v.id = a.venue_id
                JOIN portal_users u ON u.id = a.portal_user_id
                WHERE v.number = :number AND a.status = :active
                ORDER BY u.last_name, u.first_name
                SQL,
            ['number' => $number, 'active' => PortalVenueAccessStatus::Active->value],
        );

        return array_map(
            static fn (array $row): VenueManagerRow => new VenueManagerRow((string) $row['id'], (string) $row['first_name'], (string) $row['last_name'], (string) $row['email'], (bool) $row['invited']),
            $rows,
        );
    }

    /**
     * Any account counts, unconfirmed and deactivated too: the controller decides what each one gets.
     */
    public function accountByEmail(string $email): ?ManagerAccountRow
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, email, first_name, last_name, phone, is_active, password_hash IS NULL AS invited FROM portal_users WHERE email = :email',
            ['email' => mb_strtolower($email)],
        );
        if (false === $row) {
            return null;
        }

        return new ManagerAccountRow(
            (string) $row['id'],
            (string) $row['email'],
            (string) $row['first_name'],
            (string) $row['last_name'],
            null === $row['phone'] ? null : (string) $row['phone'],
            (bool) $row['is_active'],
            (bool) $row['invited'],
        );
    }

    /**
     * Who gets a service mail about the venue (G14, D52): the managers with active access on an active account, no
     * fallback to the inquiry address.
     *
     * @return list<array{email: string, name: string}>
     */
    public function mailRecipients(string $venueId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT u.email, u.first_name
                FROM portal_venue_access a
                JOIN portal_users u ON u.id = a.portal_user_id
                WHERE a.venue_id = :venue AND a.status = :active AND u.is_active
                ORDER BY u.email
                SQL,
            ['venue' => $venueId, 'active' => PortalVenueAccessStatus::Active->value],
        );

        return array_map(static fn (array $row): array => ['email' => (string) $row['email'], 'name' => (string) $row['first_name']], $rows);
    }
}
