<?php

declare(strict_types=1);

namespace App\Stats;

use App\Stats\ValueObject\SpoolEvent;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Rebuilds `stats_daily` for whole Zurich days from the raw `stats_events` (#47, contract C5). A day is
 * deleted and computed again, never added to, so a rerun gives the same rows and a later bot filter
 * can recompute history. Call it inside the ingest's transaction.
 *
 * - Site rows (venue_number 0): `page_view` over all page views, and `page_view_<page>` per page type.
 * - Venue rows: one per venue number in `venues` that exists in `venues.number`, so a forged number
 *   makes no row; a page view counts as `detail_view` only on the detail page.
 * - `automated` events count nowhere. Visitors are the distinct visitor hashes, so 0 for inquiries.
 */
final readonly class StatsRollup
{
    private const string SITE_TOTAL = <<<'SQL'
        INSERT INTO stats_daily (day, venue_number, metric, events, visitors)
        SELECT CAST(:day AS DATE), 0, 'page_view', count(*), count(DISTINCT e.visitor_hash)
        FROM stats_events e
        WHERE e.occurred_at >= CAST(:from AS TIMESTAMPTZ) AND e.occurred_at < CAST(:to AS TIMESTAMPTZ)
          AND NOT e.automated
          AND e.type = 'page_view'
        HAVING count(*) > 0
        SQL;

    private const string SITE_PAGES = <<<'SQL'
        INSERT INTO stats_daily (day, venue_number, metric, events, visitors)
        SELECT CAST(:day AS DATE), 0, 'page_view_' || e.page, count(*), count(DISTINCT e.visitor_hash)
        FROM stats_events e
        WHERE e.occurred_at >= CAST(:from AS TIMESTAMPTZ) AND e.occurred_at < CAST(:to AS TIMESTAMPTZ)
          AND NOT e.automated
          AND e.type = 'page_view'
          AND e.page IS NOT NULL
        GROUP BY e.page
        SQL;

    private const string VENUES = <<<'SQL'
        INSERT INTO stats_daily (day, venue_number, metric, events, visitors)
        SELECT CAST(:day AS DATE), v.number, m.metric, count(*), count(DISTINCT e.visitor_hash)
        FROM stats_events e
        CROSS JOIN LATERAL jsonb_array_elements_text(e.venues) AS n(number)
        JOIN venues v ON v.number = n.number::INTEGER
        CROSS JOIN LATERAL (SELECT CASE e.type
            WHEN 'page_view' THEN 'detail_view'
            WHEN 'click' THEN 'click_' || e.kind
            ELSE e.type
        END AS metric) m
        WHERE e.occurred_at >= CAST(:from AS TIMESTAMPTZ) AND e.occurred_at < CAST(:to AS TIMESTAMPTZ)
          AND NOT e.automated
          AND e.venues IS NOT NULL
          AND (e.type <> 'page_view' OR e.page = 'detail')
        GROUP BY v.number, m.metric
        SQL;

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param list<string> $days Zurich days as `Y-m-d`
     */
    public function rebuild(array $days): void
    {
        if ([] === $days) {
            return;
        }

        $zone = new \DateTimeZone(SpoolEvent::ZONE);
        $bounds = [];
        foreach ($days as $day) {
            $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, $zone);
            if (false === $from || $from->format('Y-m-d') !== $day) {
                throw new \InvalidArgumentException(\sprintf('"%s" is not a day (Y-m-d).', $day));
            }
            $bounds[$day] = [
                'day' => $day,
                'from' => $from->format(\DATE_ATOM),
                // Midnight of the next day in Zurich: 23 or 25 hours later on the DST days.
                'to' => $from->modify('+1 day')->format(\DATE_ATOM),
            ];
        }

        $this->connection->executeStatement(
            'DELETE FROM stats_daily WHERE day IN (:days)',
            ['days' => array_keys($bounds)],
            ['days' => ArrayParameterType::STRING],
        );

        foreach ($bounds as $parameters) {
            foreach ([self::SITE_TOTAL, self::SITE_PAGES, self::VENUES] as $sql) {
                $this->connection->executeStatement($sql, $parameters);
            }
        }
    }
}
