<?php

declare(strict_types=1);

namespace App\Stats\ReadModel;

/**
 * One venue's figures over one calendar month (Europe/Zurich), for the monthly report of G10. `deliveredInquiries`
 * counts `funnel_events` `inquiry_delivered` (the `inquiries` rows are purged, the funnel rows are not).
 * `qualifiedInquiries` stays null in this cycle: D52 keeps the qualified figure out of the monthly mail, which shows
 * no row for a null. A follow-up that wants it fills it with G7's `QualifiedInquirySql::where()`.
 */
final readonly class VenueMonth
{
    public function __construct(
        public \DateTimeImmutable $month,
        public VenueSummary $summary,
        public int $deliveredInquiries,
        public ?int $qualifiedInquiries = null,
    ) {
    }
}
