<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * The venues that have no company yet, whatever their status (D19: the rule is "every venue").
 */
final class UnlinkedVenuesQuery
{
    private const string UNLINKED = <<<'SQL'
        FROM venues v
        WHERE NOT EXISTS (SELECT 1 FROM crm_companies c WHERE c.venue_id = v.id)
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * The venue ids by number, lowest number first. The number names a venue in a report, the id
     * addresses it (P4).
     *
     * @return array<int, string>
     */
    public function numbers(?int $limit = null): array
    {
        $sql = 'SELECT v.number, v.id '.self::UNLINKED.' ORDER BY v.number';
        if (null === $limit) {
            $rows = $this->connection->fetchAllAssociative($sql);
        } else {
            $rows = $this->connection->fetchAllAssociative($sql.' LIMIT :limit', ['limit' => $limit], ['limit' => ParameterType::INTEGER]);
        }

        $numbers = [];
        foreach ($rows as $row) {
            $numbers[(int) $row['number']] = (string) $row['id'];
        }

        return $numbers;
    }

    public function remaining(): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) '.self::UNLINKED);
    }

    /**
     * Venues whose inquiry address another venue also has: likely one operator, which the bootstrap
     * still leaves as one company per venue (CRM-1). Over all venues, so the figure does not shrink
     * as a run goes on.
     */
    public function sharingInquiryEmail(): int
    {
        return (int) $this->connection->fetchOne(<<<'SQL'
            SELECT coalesce(sum(shared.venues), 0)
            FROM (
                SELECT count(*) AS venues
                FROM venues
                WHERE inquiry_email IS NOT NULL
                GROUP BY lower(inquiry_email)
                HAVING count(*) > 1
            ) shared
            SQL);
    }
}
