<?php

declare(strict_types=1);

namespace App\Inquiries\ReadModel;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * Which inquiries get the feedback mail now (G16): delivered and counted qualified (`QualifiedInquirySql`, so never
 * spam, test, a duplicate or an undelivered one), with the venue mail at least `DELAY_DAYS` and less than
 * `WINDOW_DAYS` ago, and not handled before (no `inquiry_feedback` row). The window lets a missed night catch up and
 * keeps older inquiries out. Grouped by the guest's address (case-insensitive), so an address gets one mail a run.
 */
final class FeedbackRequestsDue
{
    public const int DELAY_DAYS = 7;
    public const int WINDOW_DAYS = 14;

    private const int LIMIT = 500;

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
                SELECT i.id, lower(i.email) AS address,
                    EXISTS (
                        SELECT 1 FROM inquiry_feedback f JOIN inquiries o ON o.id = f.inquiry_id
                        WHERE f.opted_out_at IS NOT NULL AND lower(o.email) = lower(i.email)
                    ) AS opted_out
                FROM inquiries i
                WHERE i.venue_mail_status = 'sent'
                    AND {$qualified}
                    AND i.venue_mail_sent_at <= :before
                    AND i.venue_mail_sent_at > :since
                    AND NOT EXISTS (SELECT 1 FROM inquiry_feedback f WHERE f.inquiry_id = i.id)
                ORDER BY lower(i.email), i.venue_mail_sent_at, i.id
                LIMIT {$limit}
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
