<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use Doctrine\DBAL\Connection;

/**
 * Every contact row with its Location's name, for the contact CSV (CRM-6, #55 D7): no filter, by Location, then
 * person. One streamed statement.
 */
final class ContactCsvQuery
{
    private const string SQL = <<<'SQL'
        SELECT p.first_name, p.last_name, c.name AS company_name, p.position, p.email, p.phone
        FROM crm_contacts p
        JOIN crm_companies c ON c.id = p.company_id
        WHERE NOT
        SQL;

    private const string ORDER = <<<'SQL'
        ORDER BY lower(c.name), lower(p.last_name), lower(coalesce(p.first_name, '')), p.id
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return iterable<ContactCsvRow>
     */
    public function rows(): iterable
    {
        // A suppressed Location's contacts are never in it (G11), and so is no suppressed address (the rule covers both).
        foreach ($this->connection->iterateAssociative(self::SQL.' '.SuppressionQuery::LOCATION_SUPPRESSED.' '.self::ORDER) as $row) {
            yield new ContactCsvRow(
                null === $row['first_name'] ? null : (string) $row['first_name'],
                (string) $row['last_name'],
                (string) $row['company_name'],
                null === $row['position'] ? null : (string) $row['position'],
                null === $row['email'] ? null : (string) $row['email'],
                null === $row['phone'] ? null : (string) $row['phone'],
            );
        }
    }
}
