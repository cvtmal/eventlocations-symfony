<?php

declare(strict_types=1);

namespace App\Portal\ReadModel;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * The open access requests for staff, oldest first. A deactivated account stays listed: it is staff's call, and
 * its row would otherwise wait unseen until a reactivation.
 */
final class PortalAccessRequestQuery
{
    private const string PENDING = <<<'SQL'
        SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.is_active, v.number, v.name, a.requested_at
        FROM portal_venue_access a
        JOIN portal_users u ON u.id = a.portal_user_id
        JOIN venues v ON v.id = a.venue_id
        WHERE a.status = 'pending'
        ORDER BY a.requested_at, v.number
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<PortalAccessRequestRow>
     */
    public function oldest(?int $limit = null): array
    {
        $rows = null === $limit
            ? $this->connection->fetchAllAssociative(self::PENDING)
            : $this->connection->fetchAllAssociative(self::PENDING.' LIMIT :limit', ['limit' => $limit], ['limit' => ParameterType::INTEGER]);

        return array_map(static fn (array $row): PortalAccessRequestRow => new PortalAccessRequestRow(
            (string) $row['id'],
            (string) $row['first_name'],
            (string) $row['last_name'],
            (string) $row['email'],
            null === $row['phone'] ? null : (string) $row['phone'],
            (int) $row['number'],
            (string) $row['name'],
            new \DateTimeImmutable((string) $row['requested_at']),
            (bool) $row['is_active'],
        ), $rows);
    }
}
