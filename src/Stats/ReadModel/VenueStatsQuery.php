<?php

declare(strict_types=1);

namespace App\Stats\ReadModel;

use App\Stats\ValueObject\ClickKind;
use Doctrine\DBAL\Connection;

/**
 * One venue's figures of #47 from `stats_daily` (contract C5), for the CMS "Statistik" tab and
 * `/konto`. Venues are addressed by number; a period is a range of Zurich days, both ends included.
 */
final class VenueStatsQuery
{
    private const string ZONE = 'Europe/Zurich';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function summary(int $number, \DateTimeImmutable $from, \DateTimeImmutable $to): VenueSummary
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT s.metric, SUM(s.events) AS events, SUM(s.visitors) AS visitors
                FROM stats_daily s
                WHERE s.venue_number = :number AND s.day BETWEEN CAST(:from AS DATE) AND CAST(:to AS DATE)
                GROUP BY s.metric
                SQL,
            ['number' => $number] + self::range($from, $to),
        );

        $events = [];
        $visitors = [];
        foreach ($rows as $row) {
            $events[(string) $row['metric']] = (int) $row['events'];
            $visitors[(string) $row['metric']] = (int) $row['visitors'];
        }

        $clicks = [];
        foreach (ClickKind::cases() as $kind) {
            $clicks[$kind->value] = $events['click_'.$kind->value] ?? 0;
        }

        return new VenueSummary(
            $events['detail_view'] ?? 0,
            $visitors['detail_view'] ?? 0,
            $events['impression'] ?? 0,
            $clicks,
            array_sum($clicks),
            $events['merkliste_add'] ?? 0,
            $events['inquiry'] ?? 0,
        );
    }

    /**
     * Detail views per day, one point per day of the range.
     *
     * @return list<DailyPoint>
     */
    public function dailyDetailViews(int $number, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT to_char(g.day, 'YYYY-MM-DD') AS day, COALESCE(s.events, 0) AS events
                FROM generate_series(CAST(:from AS TIMESTAMP), CAST(:to AS TIMESTAMP), INTERVAL '1 day') AS g(day)
                LEFT JOIN stats_daily s ON s.day = CAST(g.day AS DATE) AND s.venue_number = :number AND s.metric = 'detail_view'
                ORDER BY g.day
                SQL,
            ['number' => $number] + self::range($from, $to),
        );

        return array_map(static function (array $row): DailyPoint {
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $row['day'], new \DateTimeZone(self::ZONE));
            \assert(false !== $day);

            return new DailyPoint($day, (int) $row['events']);
        }, $rows);
    }

    /**
     * @return array{from: string, to: string}
     */
    private static function range(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
    }
}
