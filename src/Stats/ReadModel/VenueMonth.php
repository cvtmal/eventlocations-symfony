<?php

declare(strict_types=1);

namespace App\Stats\ReadModel;

/**
 * One venue's figures over one calendar month (Europe/Zurich), for the monthly report of G10. `deliveredInquiries`
 * counts `funnel_events` `inquiry_delivered` (the `inquiries` rows are purged, the funnel rows are not).
 * `qualifiedInquiries` counts the venue's inquiries by the month of their venue mail through G7's
 * `QualifiedInquirySql::where()`; `feedbackAnswers` is G16's «Rückmeldungen» (`InquiryFeedbackCounts`), answers of any
 * kind, never bookings.
 */
final readonly class VenueMonth
{
    public function __construct(
        public \DateTimeImmutable $month,
        public VenueSummary $summary,
        public int $deliveredInquiries,
        public int $qualifiedInquiries = 0,
        public int $feedbackAnswers = 0,
    ) {
    }
}
