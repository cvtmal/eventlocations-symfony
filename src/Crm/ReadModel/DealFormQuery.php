<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * The second step of "Deal erfassen" in one statement (N5): the Location and its Eintrag (#56).
 */
final class DealFormQuery
{
    private const string SQL = <<<'SQL'
        SELECT c.id, c.name, v.id AS venue_id, v.name AS venue_name
        FROM crm_companies c
        LEFT JOIN venues v ON v.id = c.venue_id
        WHERE c.id = :id
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Null for an unknown or malformed id, so no 22P02 reaches a 500.
     */
    public function company(string $companyId): ?DealFormCompany
    {
        if (!Uuid::isValid($companyId)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(self::SQL, ['id' => $companyId]);
        if (false === $row) {
            return null;
        }

        return new DealFormCompany(
            (string) $row['id'],
            (string) $row['name'],
            null === $row['venue_id'] ? null : new DealFormOption((string) $row['venue_id'], (string) $row['venue_name']),
        );
    }
}
