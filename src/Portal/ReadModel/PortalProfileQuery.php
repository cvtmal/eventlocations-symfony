<?php

declare(strict_types=1);

namespace App\Portal\ReadModel;

use Doctrine\DBAL\Connection;

/**
 * What the profile page shows. `PortalIdentity` is a session snapshot without the phone, so the page reads here.
 */
final class PortalProfileQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function find(string $id): ?PortalProfileRow
    {
        $row = $this->connection->fetchAssociative('SELECT first_name, last_name, phone, email FROM portal_users WHERE id = :id', ['id' => $id]);

        if (false === $row) {
            return null;
        }

        return new PortalProfileRow(
            (string) $row['first_name'],
            (string) $row['last_name'],
            null === $row['phone'] ? null : (string) $row['phone'],
            (string) $row['email'],
        );
    }
}
