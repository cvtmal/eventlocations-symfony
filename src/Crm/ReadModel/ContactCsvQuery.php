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
        SELECT p.first_name, p.last_name, co.name AS company_name, p.position, p.email, p.phone
        FROM crm_contacts p
        JOIN crm_companies co ON co.id = p.company_id
        ORDER BY lower(co.name), lower(p.last_name), lower(coalesce(p.first_name, '')), p.id
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
        foreach ($this->connection->iterateAssociative(self::SQL) as $row) {
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
