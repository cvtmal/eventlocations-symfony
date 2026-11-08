<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\OutreachCohort;
use App\Shared\Funnel\FunnelEvent;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The funnel by pilot cohort (G11, CMO §6.5) on the Akquise page: per cohort A-D the Locations, how many were sent,
 * came back and are suppressed, and per supply step of G8's `funnel_events` how many of the cohort's Eintraege reached
 * it **on or after the Location's send day** (Europe/Zurich). What a venue did before the letter or mail is not the
 * outreach's doing, and a Location without a send day counts no step. The cohort lives on the Location, so a link sent
 * by e-mail (no campaign code, PO decision) is counted as well as a letter's.
 *
 * Crm may not use Stats, so this reads the `funnel_events` table by SQL and never a Stats class. Two statements; both
 * stay small (a few hundred pilot Locations, the `(venue_id, occurred_at)` index of `funnel_events`).
 */
final class CohortFunnelQuery
{
    /** The steps the table shows, in funnel order: the supply side up to the first payment. */
    public const array STEPS = [
        FunnelEvent::PreviewOpened,
        FunnelEvent::ClaimStarted,
        FunnelEvent::ClaimVerified,
        FunnelEvent::VenuePublished,
        FunnelEvent::InquiryDelivered,
        FunnelEvent::ProPaid,
    ];

    private const string ZONE = 'Europe/Zurich';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function funnel(): CohortFunnel
    {
        $counts = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT c.cohort,
                    COUNT(*) AS locations,
                    COUNT(*) FILTER (WHERE c.outreach_sent_on IS NOT NULL) AS sent,
                    COUNT(*) FILTER (WHERE c.mail_returned_on IS NOT NULL) AS returned,
                    COUNT(*) FILTER (WHERE '.SuppressionQuery::LOCATION_SUPPRESSED.') AS suppressed
             FROM crm_companies c
             WHERE c.cohort IS NOT NULL
             GROUP BY c.cohort',
        ) as $row) {
            $counts[(string) $row['cohort']] = $row;
        }

        $steps = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT c.cohort, f.event, COUNT(DISTINCT c.venue_id) AS venues
             FROM crm_companies c
             JOIN funnel_events f ON f.venue_id = c.venue_id AND f.occurred_at >= (c.outreach_sent_on::timestamp AT TIME ZONE :zone)
             WHERE c.cohort IS NOT NULL AND c.outreach_sent_on IS NOT NULL AND f.event IN (:events)
             GROUP BY c.cohort, f.event',
            ['zone' => self::ZONE, 'events' => array_map(static fn (FunnelEvent $step): string => $step->value, self::STEPS)],
            ['events' => ArrayParameterType::STRING],
        ) as $row) {
            $steps[(string) $row['cohort']][(string) $row['event']] = (int) $row['venues'];
        }

        $rows = [];
        foreach (OutreachCohort::cases() as $cohort) {
            $count = $counts[$cohort->value] ?? null;
            $rows[] = new CohortFunnelRow(
                $cohort,
                (int) ($count['locations'] ?? 0),
                (int) ($count['sent'] ?? 0),
                (int) ($count['returned'] ?? 0),
                (int) ($count['suppressed'] ?? 0),
                array_map(static fn (FunnelEvent $step): int => $steps[$cohort->value][$step->value] ?? 0, self::STEPS),
            );
        }

        return new CohortFunnel(self::STEPS, $rows);
    }
}
