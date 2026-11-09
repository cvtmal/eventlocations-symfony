<?php

declare(strict_types=1);

namespace App\Inquiries\ReadModel;

use Doctrine\DBAL\Connection;

/**
 * The public pages of a brief (F13): the guest's confirmation and the venue's opt-out page.
 */
final readonly class BriefQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * The venues of a brief in the guest's order; empty for an unknown brief (or one the retention deleted).
     *
     * @return list<BriefRecipient>
     */
    public function recipients(string $briefId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT venue_name, venue_slug, venue_mail_status = 'sent' AS reached FROM inquiries WHERE brief_id = ? ORDER BY id",
            [$briefId],
        );

        return array_map(static fn (array $row): BriefRecipient => new BriefRecipient((string) $row['venue_name'], (string) $row['venue_slug'], (bool) $row['reached']), $rows);
    }

    public function optOutVenue(int $number): ?BriefOptOutVenue
    {
        $row = $this->connection->fetchAssociative('SELECT name, accepts_multi_inquiries FROM venues WHERE number = ?', [$number]);

        return false === $row ? null : new BriefOptOutVenue($number, (string) $row['name'], (bool) $row['accepts_multi_inquiries']);
    }
}
