<?php

declare(strict_types=1);

namespace App\Stats\ReadModel;

use App\Stats\ValueObject\PageType;
use Doctrine\DBAL\Connection;

/**
 * The site-wide figures of #47 from `stats_daily` (contract C5), for the staff statistics screen, its
 * dashboard widget and the media kit. Every period is a range of Zurich days, both ends included;
 * only the date part of `$from`, `$to` and `$today` counts.
 */
final class SiteStatsQuery
{
    private const string ZONE = 'Europe/Zurich';

    /**
     * The SiteSummary columns over the `stats_daily s` rows in scope. Site rows (venue 0) give visits
     * and page views; detail views, clicks, adds and inquiries come from venue rows, so they match
     * the venue table (a number no venue has never makes a row).
     */
    private const string FIGURES = <<<'SQL'
        COALESCE(SUM(s.visitors) FILTER (WHERE s.venue_number = 0 AND s.metric = 'page_view'), 0) AS visits,
        COALESCE(SUM(s.events) FILTER (WHERE s.venue_number = 0 AND s.metric = 'page_view'), 0) AS page_views,
        COALESCE(SUM(s.events) FILTER (WHERE s.venue_number > 0 AND s.metric = 'detail_view'), 0) AS detail_views,
        COALESCE(SUM(s.events) FILTER (WHERE s.venue_number > 0 AND starts_with(s.metric, 'click_')), 0) AS contact_clicks,
        COALESCE(SUM(s.events) FILTER (WHERE s.venue_number > 0 AND s.metric = 'merkliste_add'), 0) AS merkliste_adds,
        COALESCE(SUM(s.events) FILTER (WHERE s.venue_number > 0 AND s.metric = 'inquiry'), 0) AS inquiries
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function summary(\DateTimeImmutable $from, \DateTimeImmutable $to): SiteSummary
    {
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::FIGURES.' FROM stats_daily s WHERE s.day BETWEEN CAST(:from AS DATE) AND CAST(:to AS DATE)',
            self::range($from, $to),
        );

        return new SiteSummary(
            (int) ($row['visits'] ?? 0),
            (int) ($row['page_views'] ?? 0),
            (int) ($row['detail_views'] ?? 0),
            (int) ($row['contact_clicks'] ?? 0),
            (int) ($row['merkliste_adds'] ?? 0),
            (int) ($row['inquiries'] ?? 0),
        );
    }

    /**
     * Page views per day, one point per day of the range.
     *
     * @return list<DailyPoint>
     */
    public function dailyPageViews(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->daily($from, $to, 'events');
    }

    /**
     * Distinct visitors per day, one point per day of the range.
     *
     * @return list<DailyPoint>
     */
    public function dailyVisits(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->daily($from, $to, 'visitors');
    }

    /**
     * Every `PageType`, most page views first (ties in case order), 0 for a type without views.
     *
     * @return list<PageTypeRow>
     */
    public function pageTypes(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT substr(s.metric, 11) AS page, SUM(s.events) AS page_views, SUM(s.visitors) AS visitors
                FROM stats_daily s
                WHERE s.venue_number = 0 AND starts_with(s.metric, 'page_view_')
                  AND s.day BETWEEN CAST(:from AS DATE) AND CAST(:to AS DATE)
                GROUP BY s.metric
                SQL,
            self::range($from, $to),
        );

        $byPage = array_column($rows, null, 'page');
        $result = [];
        foreach (PageType::cases() as $type) {
            $row = $byPage[$type->value] ?? null;
            $result[] = new PageTypeRow($type, (int) ($row['page_views'] ?? 0), (int) ($row['visitors'] ?? 0));
        }

        // usort is stable, so equal counts keep the case order.
        usort($result, static fn (PageTypeRow $a, PageTypeRow $b): int => $b->pageViews <=> $a->pageViews);

        return $result;
    }

    /**
     * Every venue with at least one `stats_daily` row in the range, most detail views first, then by number.
     *
     * @return list<VenueRow>
     */
    public function venues(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT v.number, v.name,
                       COALESCE(SUM(s.events) FILTER (WHERE s.metric = 'detail_view'), 0) AS detail_views,
                       COALESCE(SUM(s.events) FILTER (WHERE s.metric = 'impression'), 0) AS impressions,
                       COALESCE(SUM(s.events) FILTER (WHERE starts_with(s.metric, 'click_')), 0) AS clicks,
                       COALESCE(SUM(s.events) FILTER (WHERE s.metric = 'merkliste_add'), 0) AS merkliste_adds,
                       COALESCE(SUM(s.events) FILTER (WHERE s.metric = 'inquiry'), 0) AS inquiries
                FROM stats_daily s
                JOIN venues v ON v.number = s.venue_number
                WHERE s.venue_number > 0 AND s.day BETWEEN CAST(:from AS DATE) AND CAST(:to AS DATE)
                GROUP BY v.number, v.name
                ORDER BY detail_views DESC, v.number
                SQL,
            self::range($from, $to),
        );

        return array_map(static fn (array $row): VenueRow => new VenueRow(
            (int) $row['number'],
            (string) $row['name'],
            (int) $row['detail_views'],
            (int) $row['impressions'],
            (int) $row['clicks'],
            (int) $row['merkliste_adds'],
            (int) $row['inquiries'],
        ), $rows);
    }

    /**
     * The month of `$today` (up to and including today) and the `$count - 1` months before it,
     * oldest first, a month without rows carrying zeros.
     *
     * @param positive-int $count
     *
     * @return list<MonthRow>
     */
    public function months(\DateTimeImmutable $today, int $count = 12): array
    {
        $last = $today->modify('first day of this month');
        $first = $last->modify(\sprintf('-%d months', $count - 1));

        $rows = $this->connection->fetchAllAssociative(
            'SELECT to_char(m.month, \'YYYY-MM-DD\') AS month, '.self::FIGURES.<<<'SQL'

                FROM generate_series(CAST(:first AS TIMESTAMP), CAST(:last AS TIMESTAMP), INTERVAL '1 month') AS m(month)
                LEFT JOIN stats_daily s
                       ON s.day >= m.month AND s.day < m.month + INTERVAL '1 month' AND s.day <= CAST(:today AS DATE)
                GROUP BY m.month
                ORDER BY m.month
                SQL,
            ['first' => $first->format('Y-m-d'), 'last' => $last->format('Y-m-d'), 'today' => $today->format('Y-m-d')],
        );

        return array_map(static fn (array $row): MonthRow => new MonthRow(
            self::day((string) $row['month']),
            (int) $row['visits'],
            (int) $row['page_views'],
            (int) $row['detail_views'],
            (int) $row['contact_clicks'],
            (int) $row['merkliste_adds'],
            (int) $row['inquiries'],
        ), $rows);
    }

    /**
     * When the last ingest (nightly or the staff button) loaded a spool file; null before the first.
     */
    public function lastIngestAt(): ?\DateTimeImmutable
    {
        $at = $this->connection->fetchOne('SELECT max(created_at) FROM stats_ingest_files');

        return \is_string($at) ? new \DateTimeImmutable($at) : null;
    }

    /**
     * @param 'events'|'visitors' $column
     *
     * @return list<DailyPoint>
     */
    private function daily(\DateTimeImmutable $from, \DateTimeImmutable $to, string $column): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT to_char(g.day, 'YYYY-MM-DD') AS day, COALESCE(s.events, 0) AS events, COALESCE(s.visitors, 0) AS visitors
                FROM generate_series(CAST(:from AS TIMESTAMP), CAST(:to AS TIMESTAMP), INTERVAL '1 day') AS g(day)
                LEFT JOIN stats_daily s ON s.day = CAST(g.day AS DATE) AND s.venue_number = 0 AND s.metric = 'page_view'
                ORDER BY g.day
                SQL,
            self::range($from, $to),
        );

        return array_map(static fn (array $row): DailyPoint => new DailyPoint(self::day((string) $row['day']), (int) $row[$column]), $rows);
    }

    /**
     * @return array{from: string, to: string}
     */
    private static function range(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
    }

    private static function day(string $day): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, new \DateTimeZone(self::ZONE));
        \assert(false !== $date);

        return $date;
    }
}
