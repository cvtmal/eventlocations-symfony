<?php

declare(strict_types=1);

namespace App\Portal\ReadModel;

use Doctrine\DBAL\Connection;

/**
 * What managers changed on published locations, newest first (POR-9). Their account writes sit on subject
 * `portal_user`, and a draft has nothing for staff to see yet, so neither reaches the dashboard.
 */
final class LocationChangesQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<LocationChangeRow>
     */
    public function recent(int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT v.number, v.name, p.first_name || ' ' || p.last_name AS manager_name, l.command, l.created_at
                FROM admin_audit_log l
                JOIN venues v ON v.number::text = l.subject_id
                LEFT JOIN portal_users p ON p.id = l.portal_user_id
                WHERE l.actor_type = 'portal_user' AND l.subject_type = 'venue' AND v.status = 'published'
                ORDER BY l.created_at DESC, l.id DESC
                LIMIT :limit
                SQL,
            ['limit' => $limit],
        );

        return array_map(
            static fn (array $row): LocationChangeRow => new LocationChangeRow(
                (int) $row['number'],
                (string) $row['name'],
                null === $row['manager_name'] ? null : (string) $row['manager_name'],
                (string) $row['command'],
                new \DateTimeImmutable((string) $row['created_at']),
            ),
            $rows,
        );
    }
}
