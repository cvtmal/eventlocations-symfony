<?php

declare(strict_types=1);

namespace App\Stats\ReadModel;

use App\Shared\Funnel\FunnelEvent;
use Doctrine\DBAL\Connection;

/**
 * One venue's figures per calendar month (Europe/Zurich), for the monthly report of G10. A call costs `$count` + 1
 * statements: one `VenueStatsQuery::summary()` per month over `stats_daily`, and one over `funnel_events` for the
 * delivered inquiries of all months.
 */
final class VenueMonthQuery
{
    private const string ZONE = 'Europe/Zurich';

    public function __construct(
        private readonly VenueStatsQuery $venueStats,
        private readonly Connection $connection,
    ) {
    }

    /**
     * `$month` is the first day of the newest month; the list is newest first, a month without rows is zero.
     *
     * @param int<1, 12> $count
     *
     * @return list<VenueMonth>
     */
    public function months(string $venueId, int $venueNumber, \DateTimeImmutable $month, int $count): array
    {
        $zone = new \DateTimeZone(self::ZONE);
        $newest = new \DateTimeImmutable($month->format('Y-m-01'), $zone);

        $firsts = [];
        for ($i = 0; $i < $count; ++$i) {
            $firsts[] = $newest->modify(\sprintf('-%d months', $i));
        }
        $oldest = $firsts[\count($firsts) - 1];

        $delivered = [];
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT to_char(date_trunc('month', occurred_at AT TIME ZONE 'Europe/Zurich'), 'YYYY-MM-DD') AS m, count(*) AS n
                FROM funnel_events
                WHERE event = :event AND venue_id = :venue AND occurred_at >= :from AND occurred_at < :to
                GROUP BY 1
                SQL,
            [
                'event' => FunnelEvent::InquiryDelivered->value,
                'venue' => $venueId,
                'from' => $oldest->format(\DateTimeInterface::ATOM),
                'to' => $newest->modify('+1 month')->format(\DateTimeInterface::ATOM),
            ],
        );
        foreach ($rows as $row) {
            $delivered[(string) $row['m']] = (int) $row['n'];
        }

        $months = [];
        foreach ($firsts as $first) {
            $months[] = new VenueMonth(
                $first,
                $this->venueStats->summary($venueNumber, $first, $first->modify('last day of this month')),
                $delivered[$first->format('Y-m-d')] ?? 0,
            );
        }

        return $months;
    }
}
