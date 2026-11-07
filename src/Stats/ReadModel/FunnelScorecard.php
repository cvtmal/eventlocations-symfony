<?php

declare(strict_types=1);

namespace App\Stats\ReadModel;

use App\Stats\ValueObject\FunnelFilter;
use App\Stats\ValueObject\FunnelWindow;

/**
 * What the staff funnel scorecard shows (G8). `totals` and `weeks` follow `filter`; `campaigns` always lists every
 * campaign of the window (the campaign table and the filter tabs).
 */
final readonly class FunnelScorecard
{
    /**
     * @param list<FunnelWeek>        $weeks     newest week first, every week of the window
     * @param list<FunnelCampaignRow> $campaigns by code, the row without a campaign last
     */
    public function __construct(
        public FunnelWindow $window,
        public FunnelFilter $filter,
        public FunnelCounts $totals,
        public array $weeks,
        public array $campaigns,
        public FunnelPaying $paying,
    ) {
    }
}
