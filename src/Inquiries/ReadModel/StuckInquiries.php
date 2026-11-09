<?php

declare(strict_types=1);

namespace App\Inquiries\ReadModel;

use App\Inquiries\ValueObject\MailStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * The inquiries the hourly staff alert is due to report (G7, D56): the venue mail `failed`, or is still `pending`
 * past the cut-off because its delivery ended without a result (#14), so the venue may never have heard of them.
 * Only rows created in the last `WINDOW_DAYS` days, not marked spam or test, and not alerted yet: each inquiry is
 * reported once (`stuck_alerted_at`).
 */
final class StuckInquiries
{
    /**
     * How far back the alert looks: older rows were either reported already or predate the alert.
     */
    public const int WINDOW_DAYS = 7;

    private const int LIMIT = 50;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param \DateTimeImmutable $pendingBefore a `pending` row counts when it was created before this
     * @param \DateTimeImmutable $createdSince  rows created before this are left out
     *
     * @return list<StuckInquiry> oldest first
     */
    public function due(\DateTimeImmutable $pendingBefore, \DateTimeImmutable $createdSince): array
    {
        $limit = self::LIMIT;

        /** @var list<array{id: string, venue_number: int, venue_name: string, venue_mail_status: string, created_at: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT id, venue_number, venue_name, venue_mail_status, created_at
                FROM inquiries
                WHERE stuck_alerted_at IS NULL
                    AND qualification_override IS NULL
                    AND created_at >= :since
                    AND (venue_mail_status = 'failed' OR (venue_mail_status = 'pending' AND created_at < :before))
                ORDER BY created_at, id
                LIMIT {$limit}
                SQL,
            ['before' => $pendingBefore, 'since' => $createdSince],
            ['before' => Types::DATETIMETZ_IMMUTABLE, 'since' => Types::DATETIMETZ_IMMUTABLE],
        );

        return array_map(
            static fn (array $row): StuckInquiry => new StuckInquiry(
                $row['id'],
                $row['venue_number'],
                $row['venue_name'],
                MailStatus::from($row['venue_mail_status']),
                new \DateTimeImmutable($row['created_at']),
            ),
            $rows,
        );
    }
}
