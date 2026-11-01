<?php

declare(strict_types=1);

namespace App\Inquiries\ReadModel;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * Inquiries still `pending` for the venue mail: their delivery ended without a result (#14), so
 * the venue may never have heard of them.
 */
final class StuckInquiries
{
    private const int LIMIT = 50;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<StuckInquiry> oldest first
     */
    public function olderThan(\DateTimeImmutable $before): array
    {
        $limit = self::LIMIT;

        /** @var list<array{id: string, venue_number: int, created_at: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT id, venue_number, created_at
                FROM inquiries
                WHERE venue_mail_status = 'pending'
                    AND created_at < :before
                ORDER BY created_at, id
                LIMIT {$limit}
                SQL,
            ['before' => $before],
            ['before' => Types::DATETIMETZ_IMMUTABLE],
        );

        return array_map(
            static fn (array $row): StuckInquiry => new StuckInquiry($row['id'], $row['venue_number'], new \DateTimeImmutable($row['created_at'])),
            $rows,
        );
    }
}
