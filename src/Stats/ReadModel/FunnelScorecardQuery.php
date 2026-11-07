<?php

declare(strict_types=1);

namespace App\Stats\ReadModel;

use App\Shared\Funnel\FunnelEvent;
use App\Stats\ValueObject\FunnelFilter;
use App\Stats\ValueObject\FunnelWindow;
use Doctrine\DBAL\Connection;

/**
 * The staff funnel scorecard (G8, CMO 9.1) from `funnel_events`, four statements per screen.
 *
 * - **Weeks** are ISO weeks (Monday 00:00 to Monday 00:00, Europe/Zurich); the window is the last 4, 12, 26 or 52
 *   weeks, the current one included, and every week is listed, empty ones as zeros.
 * - **Counting:** a venue step (preview, claim, publish, payment, cancellation) counts distinct venues per week or
 *   per window, so one venue opening its preview ten times is one preview; an inquiry or a brief counts rows (they
 *   carry no identity of their own). A venue step without a venue counts each row.
 * - **Campaign** of an event: its own code, else the first code ever recorded for that venue (first touch, found
 *   with a LATERAL probe of the `(venue_id, occurred_at)` index), else none ("Ohne Kampagne"). That lets Venues and
 *   Inquiries emit steps without knowing a campaign.
 * - **Paying venues:** a `pro_paid` in the last 365 days; of them the ones with a delivered inquiry since their first
 *   payment in that span. The qualified variant is G7's, null here.
 *
 * Query ceiling: the screen reads one bounded time range of `funnel_events` (at most 52 weeks) three times and the
 * `pro_paid` rows of 12 months once; the output is at most 52 weeks x 8 steps plus one row per campaign. The probe
 * runs once per row without an own campaign. Measured figures are in `module-stats.md`.
 */
final class FunnelScorecardQuery
{
    private const string ZONE = 'Europe/Zurich';

    /**
     * The steps that identify a venue: counted as distinct venues.
     */
    private const array VENUE_STEPS = [
        FunnelEvent::PreviewOpened,
        FunnelEvent::ClaimStarted,
        FunnelEvent::ClaimVerified,
        FunnelEvent::VenuePublished,
        FunnelEvent::ProPaid,
        FunnelEvent::SubscriptionCancelled,
    ];

    /**
     * Every event of the window with its campaign.
     */
    private const string SCOPED = <<<'SQL'
        SELECT e.id, e.event, e.venue_id, e.occurred_at, COALESCE(e.campaign_code, fc.campaign_code) AS campaign
          FROM funnel_events e
          LEFT JOIN LATERAL (
              SELECT f.campaign_code
                FROM funnel_events f
               WHERE e.campaign_code IS NULL AND f.venue_id = e.venue_id AND f.campaign_code IS NOT NULL
               ORDER BY f.occurred_at, f.id
               LIMIT 1
          ) fc ON TRUE
         WHERE e.occurred_at >= CAST(:from AS TIMESTAMPTZ) AND e.occurred_at < CAST(:to AS TIMESTAMPTZ)
        SQL;

    private const string PAYING = <<<'SQL'
        WITH paid AS (
            SELECT venue_id, MIN(occurred_at) AS paid_at
              FROM funnel_events
             WHERE event = 'pro_paid' AND venue_id IS NOT NULL AND occurred_at >= CAST(:since AS TIMESTAMPTZ)
             GROUP BY venue_id
        )
        SELECT COUNT(*) AS paying,
               COUNT(*) FILTER (WHERE EXISTS (
                   SELECT 1 FROM funnel_events i
                    WHERE i.event = 'inquiry_delivered' AND i.venue_id = paid.venue_id AND i.occurred_at >= paid.paid_at
               )) AS with_inquiry
          FROM paid
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function scorecard(FunnelWindow $window, FunnelFilter $filter, \DateTimeImmutable $now): FunnelScorecard
    {
        $zone = new \DateTimeZone(self::ZONE);
        $first = $window->firstWeek($now->setTimezone($zone));
        $weeks = [];
        for ($week = $first, $i = 0; $i < $window->value; $week = $week->modify('+1 week'), ++$i) {
            $weeks[] = $week;
        }
        $range = ['from' => $first->format(\DATE_ATOM), 'to' => $first->modify(\sprintf('+%d weeks', $window->value))->format(\DATE_ATOM)];

        [$where, $filterParams] = self::filterSql($filter);
        $scoped = '('.self::SCOPED.') s';

        $byWeek = [];
        foreach ($this->connection->fetchAllAssociative(
            "SELECT date_trunc('week', s.occurred_at AT TIME ZONE '".self::ZONE."')::date AS week, s.event, COUNT(DISTINCT ".self::unit().") AS n FROM {$scoped} WHERE {$where} GROUP BY 1, 2",
            $range + $filterParams,
        ) as $row) {
            $byWeek[(string) $row['week']][(string) $row['event']] = (int) $row['n'];
        }

        $totals = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT s.event, COUNT(DISTINCT '.self::unit()." ) AS n FROM {$scoped} WHERE {$where} GROUP BY 1",
            $range + $filterParams,
        ) as $row) {
            $totals[(string) $row['event']] = (int) $row['n'];
        }

        $byCampaign = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT s.campaign, s.event, COUNT(DISTINCT '.self::unit().") AS n FROM {$scoped} GROUP BY 1, 2",
            $range,
        ) as $row) {
            $byCampaign[null === $row['campaign'] ? '' : (string) $row['campaign']][(string) $row['event']] = (int) $row['n'];
        }

        return new FunnelScorecard(
            $window,
            $filter,
            new FunnelCounts($totals),
            array_reverse(array_map(
                static fn (\DateTimeImmutable $start): FunnelWeek => new FunnelWeek($start, new FunnelCounts($byWeek[$start->format('Y-m-d')] ?? [])),
                $weeks,
            )),
            self::campaignRows($byCampaign),
            $this->paying($now),
        );
    }

    /**
     * @param array<string, array<string, int>> $byCampaign counts per campaign code ('' for none) and event
     *
     * @return list<FunnelCampaignRow>
     */
    private static function campaignRows(array $byCampaign): array
    {
        $codes = array_map('strval', array_keys($byCampaign));
        sort($codes, \SORT_STRING);

        $rows = [];
        foreach ($codes as $code) {
            if ('' !== $code) {
                $rows[] = new FunnelCampaignRow($code, new FunnelCounts($byCampaign[$code]));
            }
        }
        if (isset($byCampaign[''])) {
            $rows[] = new FunnelCampaignRow(null, new FunnelCounts($byCampaign['']));
        }

        return $rows;
    }

    private function paying(\DateTimeImmutable $now): FunnelPaying
    {
        $row = $this->connection->fetchAssociative(self::PAYING, ['since' => $now->modify('-365 days')->format(\DATE_ATOM)]);

        return new FunnelPaying((int) ($row['paying'] ?? 0), (int) ($row['with_inquiry'] ?? 0));
    }

    /**
     * @return array{string, array<string, string>} the WHERE condition on the scoped rows and its parameters
     */
    private static function filterSql(FunnelFilter $filter): array
    {
        if ($filter->withoutCampaign) {
            return ['s.campaign IS NULL', []];
        }
        if (null !== $filter->campaign) {
            return ['s.campaign = :campaign', ['campaign' => $filter->campaign]];
        }

        return ['TRUE', []];
    }

    /**
     * What one count is made of: the venue for a venue step, else the row.
     */
    private static function unit(): string
    {
        $steps = implode(', ', array_map(static fn (FunnelEvent $event): string => "'".$event->value."'", self::VENUE_STEPS));

        return "CASE WHEN s.event IN ({$steps}) AND s.venue_id IS NOT NULL THEN s.venue_id::text ELSE s.id::text END";
    }
}
