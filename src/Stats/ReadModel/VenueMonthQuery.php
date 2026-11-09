<?php

declare(strict_types=1);

namespace App\Stats\ReadModel;

use App\Inquiries\ReadModel\InquiryFeedbackCounts;
use App\Inquiries\ReadModel\QualifiedInquirySql;
use App\Shared\Funnel\FunnelEvent;
use Doctrine\DBAL\Connection;

/**
 * One venue's figures per calendar month (Europe/Zurich), for the monthly report of G10. A call costs 2 × `$count` + 2
 * statements: per month one `VenueStatsQuery::summary()` over `stats_daily` and one `InquiryFeedbackCounts::forVenue()`
 * (G16), and for all months one over `funnel_events` (delivered) and one over `inquiries` (qualified).
 *
 * Inquiries count per venue: an F13 brief child is an `inquiries` row and an `inquiry_delivered` row of its own venue,
 * so each venue of a brief counts it once.
 */
final class VenueMonthQuery
{
    private const string ZONE = 'Europe/Zurich';

    /** Delivered inquiries: the funnel rows outlive the purged `inquiries` rows. */
    private const string DELIVERED = <<<'SQL'
        SELECT to_char(date_trunc('month', occurred_at AT TIME ZONE 'Europe/Zurich'), 'YYYY-MM-DD') AS m, count(*) AS n
        FROM funnel_events
        WHERE event = :event AND venue_id = :venue AND occurred_at >= :from AND occurred_at < :to
        GROUP BY 1
        SQL;

    /** Qualified inquiries (G7) by the month of the venue mail, like G8's «Qualifizierte Anfragen» tile. */
    private const string QUALIFIED = <<<'SQL'
        SELECT to_char(date_trunc('month', i.venue_mail_sent_at AT TIME ZONE 'Europe/Zurich'), 'YYYY-MM-DD') AS m, count(*) AS n
        FROM inquiries i
        WHERE i.venue_id = :venue AND %s AND i.venue_mail_sent_at >= :from AND i.venue_mail_sent_at < :to
        GROUP BY 1
        SQL;

    public function __construct(
        private readonly VenueStatsQuery $venueStats,
        private readonly InquiryFeedbackCounts $feedbackCounts,
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
        $range = [
            'venue' => $venueId,
            'from' => $firsts[\count($firsts) - 1]->format(\DateTimeInterface::ATOM),
            'to' => $newest->modify('+1 month')->format(\DateTimeInterface::ATOM),
        ];

        $delivered = $this->perMonth(self::DELIVERED, ['event' => FunnelEvent::InquiryDelivered->value] + $range);
        $qualified = $this->perMonth(\sprintf(self::QUALIFIED, QualifiedInquirySql::where('i')), $range);

        $months = [];
        foreach ($firsts as $first) {
            $key = $first->format('Y-m-d');
            $months[] = new VenueMonth(
                $first,
                $this->venueStats->summary($venueNumber, $first, $first->modify('last day of this month')),
                $delivered[$key] ?? 0,
                $qualified[$key] ?? 0,
                $this->feedbackCounts->forVenue($venueId, $first),
            );
        }

        return $months;
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return array<string, int> count by the month's first day (`Y-m-d`)
     */
    private function perMonth(string $sql, array $parameters): array
    {
        $counts = [];
        foreach ($this->connection->fetchAllAssociative($sql, $parameters) as $row) {
            $counts[(string) $row['m']] = (int) $row['n'];
        }

        return $counts;
    }
}
