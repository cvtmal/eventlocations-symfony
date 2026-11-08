<?php

declare(strict_types=1);

namespace App\Stats\ReadModel;

/**
 * The scorecard's critical metric (CMO 9.1): of the venues that paid for Pro in the last 12 months, how many got at
 * least one delivered inquiry since their first payment, and how many got at least one counted qualified inquiry
 * (G7: qualified, not marked spam or test) since then.
 */
final readonly class FunnelPaying
{
    public function __construct(
        public int $paying,
        public int $withDeliveredInquiry,
        public int $withQualifiedInquiry,
    ) {
    }

    /**
     * Whole percent of the paying venues that got a delivered inquiry, null without paying venues.
     */
    public function deliveredPercent(): ?int
    {
        return $this->paying > 0 ? (int) round(100 * $this->withDeliveredInquiry / $this->paying) : null;
    }

    /**
     * Whole percent of the paying venues that got a qualified inquiry, null without paying venues.
     */
    public function qualifiedPercent(): ?int
    {
        return $this->paying > 0 ? (int) round(100 * $this->withQualifiedInquiry / $this->paying) : null;
    }
}
