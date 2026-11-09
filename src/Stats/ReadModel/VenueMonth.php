<?php

declare(strict_types=1);

namespace App\Stats\ReadModel;

/**
 * One venue's figures over one calendar month (Europe/Zurich), for the monthly report of G10. `deliveredInquiries`
 * counts `funnel_events` `inquiry_delivered` (the `inquiries` rows are purged, the funnel rows are not).
 * `qualifiedInquiries` is filled by G7's qualification (D19) and is null until then; the report shows no row for it
 * while it is null.
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
