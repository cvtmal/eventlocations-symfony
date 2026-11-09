<?php

declare(strict_types=1);

namespace App\Inquiries\ReadModel;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * Which inquiries get the feedback mail now (G16): delivered and counted qualified (`QualifiedInquirySql`, so never
 * spam, test, a duplicate or an undelivered one), with the venue mail less than `WINDOW_DAYS` ago, and not handled
 * before (no `inquiry_feedback` row). Grouped by the guest's address (case-insensitive); a group is due once one of its
 * inquiries had its venue mail `DELAY_DAYS` ago, and then takes every open inquiry of the address along, so a guest
 * who asked several venues within a few days gets one mail. The window lets a missed night catch up and keeps older
 * inquiries out.
 */
final class FeedbackRequestsDue
{
    public const int DELAY_DAYS = 7;
    public const int WINDOW_DAYS = 14;

    /** Addresses per run; a run never splits an address. */
    private const int LIMIT = 200;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<FeedbackRequest>
     */
    public function due(\DateTimeImmutable $now): array
    {
        $limit = self::LIMIT;
        $qualified = QualifiedInquirySql::where('i');

        /** @var list<array{id: string, address: string, opted_out: bool}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                WITH pending AS (
                    SELECT i.id, lower(i.email) AS address, i.venue_mail_sent_at
                    FROM inquiries i
                    WHERE i.venue_mail_status = 'sent'
                        AND {$qualified}
                        AND i.venue_mail_sent_at > :since
                        AND NOT EXISTS (SELECT 1 FROM inquiry_feedback f WHERE f.inquiry_id = i.id)
                ), due AS (
                    SELECT address FROM pending GROUP BY address HAVING min(venue_mail_sent_at) <= :before
                    ORDER BY address LIMIT {$limit}
                )
                SELECT o.id, o.address,
                    EXISTS (
                        SELECT 1 FROM inquiry_feedback f JOIN inquiries x ON x.id = f.inquiry_id
                        WHERE f.opted_out_at IS NOT NULL AND lower(x.email) = o.address
                    ) AS opted_out
                FROM pending o JOIN due d ON d.address = o.address
                ORDER BY o.address, o.venue_mail_sent_at, o.id
                SQL,
            [
                'before' => $now->modify(\sprintf('-%d days', self::DELAY_DAYS)),
                'since' => $now->modify(\sprintf('-%d days', self::WINDOW_DAYS)),
            ],
            ['before' => Types::DATETIMETZ_IMMUTABLE, 'since' => Types::DATETIMETZ_IMMUTABLE],
        );

        /** @var array<string, array{ids: non-empty-list<string>, optedOut: bool}> $groups */
        $groups = [];
        foreach ($rows as $row) {
            if (isset($groups[$row['address']])) {
                $groups[$row['address']]['ids'][] = $row['id'];
            } else {
                $groups[$row['address']] = ['ids' => [$row['id']], 'optedOut' => $row['opted_out']];
            }
        }

        return array_values(array_map(
            static fn (array $group): FeedbackRequest => new FeedbackRequest($group['ids'], $group['optedOut']),
            $groups,
        ));
    }
}
