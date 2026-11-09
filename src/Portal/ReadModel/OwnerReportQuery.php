<?php

declare(strict_types=1);

namespace App\Portal\ReadModel;

use App\Portal\ValueObject\ReportGaps;
use App\Portal\ValueObject\ReportMonth;
use App\Portal\ValueObject\ReportSuggestion;
use App\Shared\Translation\EnabledLocale;
use App\Stats\ReadModel\SiteStatsQuery;
use App\Stats\ReadModel\VenueMonthQuery;
use App\Venues\EntitlementResolver;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Which monthly reports are due and what each says (G10). Due: a published venue without a report for that month
 * (`portal_owner_reports`) and without an opt-out (`portal_report_opt_outs`). Whether it has anyone to mail is the
 * handler's question, as in G9.
 */
final class OwnerReportQuery
{
    /**
     * Off (tech lead, D52): the report goes only to a venue's active `/konto` managers; a venue without one gets no
     * mail and no send-log row. On, it falls back to the inquiry address as G9's reminder does.
     */
    public const bool INQUIRY_ADDRESS_FALLBACK = false;

    private const string DUE = <<<'SQL'
        FROM venues v
        WHERE v.status = 'published'
          AND NOT EXISTS (SELECT 1 FROM portal_owner_reports r WHERE r.venue_id = v.id AND r.report_month = CAST(:month AS date))
          AND NOT EXISTS (SELECT 1 FROM portal_report_opt_outs o WHERE o.venue_id = v.id)
        SQL;

    public function __construct(
        private readonly Connection $connection,
        private readonly VenueMonthQuery $months,
        private readonly SiteStatsQuery $siteStats,
        private readonly LocationPlanQuery $plans,
        private readonly EntitlementResolver $entitlements,
        private readonly VenueMailRecipients $recipients,
        private readonly EnabledLocale $locales,
        // A parameter, so a test can prove the fallback path; the constant above is the switch.
        private readonly bool $inquiryAddressFallback = self::INQUIRY_ADDRESS_FALLBACK,
    ) {
    }

    /**
     * @return list<string> venue ids by number
     */
    public function due(ReportMonth $month): array
    {
        return array_map(
            static fn (mixed $id): string => (string) $id,
            $this->connection->fetchFirstColumn('SELECT v.id '.self::DUE.' ORDER BY v.number', ['month' => $month->sql()]),
        );
    }

    /**
     * What the mail needs, or null when the venue is no longer due (sent, opted out, offline, gone).
     */
    public function report(string $venueId, ReportMonth $month, \DateTimeImmutable $now): ?OwnerReport
    {
        if (!Uuid::isValid($venueId)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT v.id, v.number, v.name, v.inquiry_email,
                       v.capacity_seated IS NOT NULL OR v.capacity_standing IS NOT NULL AS seated_or_standing,
                       v.price_level IS NOT NULL AS price_level,
                       EXISTS (SELECT 1 FROM venue_translations t WHERE t.venue_id = v.id AND t.locale = :locale AND btrim(coalesce(t.description, '')) <> '') AS description,
                       (SELECT count(*) FROM venue_photos p WHERE p.venue_id = v.id AND p.room_id IS NULL) AS gallery_photos,
                       (SELECT count(*) FROM rooms r WHERE r.venue_id = v.id) AS rooms,
                       (SELECT count(*) FROM venue_terms vt WHERE vt.venue_id = v.id AND vt.term_group = 'suitable_for') AS event_types
                SQL.' '.self::DUE.' AND v.id = :id',
            ['month' => $month->sql(), 'id' => $venueId, 'locale' => $this->locales->default()],
        );
        if (false === $row) {
            return null;
        }

        $plan = $this->plans->forVenue($venueId, $now);
        $inquiryEmail = \is_string($row['inquiry_email']) && '' !== $row['inquiry_email'] ? $row['inquiry_email'] : null;
        $gaps = new ReportGaps(
            null !== $inquiryEmail,
            (int) $row['gallery_photos'],
            $plan->photosUsed,
            $plan->photosMax,
            true === $row['description'],
            (int) $row['rooms'],
            true === $row['seated_or_standing'],
            (int) $row['event_types'],
            true === $row['price_level'],
        );
        $pro = $this->entitlements->forVenue($venueId, $now)->detailedReport;
        $months = $this->months->months($venueId, (int) $row['number'], $month->firstDay(), 3);
        $figures = OwnerReportFigures::basic($months[0], $months[1]);

        return new OwnerReport(
            $venueId,
            (int) $row['number'],
            (string) $row['name'],
            $month,
            $figures,
            $pro ? OwnerReportFigures::detailed($months[0], $months[1]) : null,
            $pro ? OwnerReportFigures::trend($months) : null,
            OwnerReportFigures::isEmpty($figures),
            ReportSuggestion::for($gaps),
            $gaps,
            $plan,
            $pro,
            $this->siteStats->lastIngestAt(),
            $this->recipients->forVenue($venueId, $this->inquiryAddressFallback ? $inquiryEmail : null),
        );
    }

    /**
     * The opt-out page's state, or null when the venue is gone.
     */
    public function optOutState(string $venueId): ?ReportOptOutState
    {
        $row = $this->connection->fetchAssociative(
            'SELECT v.name, EXISTS (SELECT 1 FROM portal_report_opt_outs o WHERE o.venue_id = v.id) AS opted_out FROM venues v WHERE v.id = :id',
            ['id' => $venueId],
        );

        return false === $row ? null : new ReportOptOutState((string) $row['name'], true === $row['opted_out']);
    }
}
