<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

/**
 * The «Sammelanfrage» an inquiry is part of (F13): how many venues the brief went to and the other venues' inquiries
 * that are still stored, ordered by venue name.
 */
final readonly class InquiryBriefSummary
{
    /**
     * @param list<InquiryBriefSibling> $others
     */
    public function __construct(
        public int $venueCount,
        public array $others,
    ) {
    }
}
