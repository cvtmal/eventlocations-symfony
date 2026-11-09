<?php

declare(strict_types=1);

namespace App\Portal\ReadModel;

use App\Portal\ValueObject\PortalVenueAccessStatus;
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
 * (`portal_owner_reports`), without an opt-out (`portal_report_opt_outs`) and with someone to mail: an active manager
 * who is an active user (`VenueMailRecipients`' rule), or with the fallback on, an inquiry address. So an unclaimed
 * venue never has its figures built, and `report()` checks the recipients before it computes any.
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

    private const string HAS_MANAGER = <<<'SQL'
        EXISTS (
            SELECT 1 FROM portal_venue_access a JOIN portal_users u ON u.id = a.portal_user_id
            WHERE a.venue_id = v.id AND a.status = :active AND u.is_active
        )
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
            $this->connection->fetchFirstColumn('SELECT v.id '.$this->dueSql().' ORDER BY v.number', $this->dueParameters($month)),
        );
    }

    /**
     * What the mail needs, or null when the venue is no longer due (sent, opted out, offline, gone, nobody to mail).
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
                SQL.' '.$this->dueSql().' AND v.id = :id',
            $this->dueParameters($month) + ['id' => $venueId, 'locale' => $this->locales->default()],
        );
        if (false === $row) {
            return null;
        }
        $inquiryEmail = \is_string($row['inquiry_email']) && '' !== $row['inquiry_email'] ? $row['inquiry_email'] : null;
        // Checked again before the figures: a manager may have left since `due()`.
        $recipients = $this->recipients->forVenue($venueId, $this->inquiryAddressFallback ? $inquiryEmail : null);
        if ([] === $recipients) {
            return null;
        }

        $plan = $this->plans->forVenue($venueId, $now);
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
            $recipients,
        );
    }

    private function dueSql(): string
    {
        return self::DUE.' AND '.($this->inquiryAddressFallback
            ? '('.self::HAS_MANAGER." OR coalesce(v.inquiry_email, '') <> '')"
            : self::HAS_MANAGER);
    }

    /**
     * @return array{month: string, active: string}
     */
    private function dueParameters(ReportMonth $month): array
    {
        return ['month' => $month->sql(), 'active' => PortalVenueAccessStatus::Active->value];
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
