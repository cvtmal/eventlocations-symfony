<?php

declare(strict_types=1);

namespace App\Stats\ReadModel;

/**
 * One campaign of the funnel scorecard over the whole window (G8).
 */
final readonly class FunnelCampaignRow
{
    /**
     * @param string|null $campaign the campaign code, null for the events no campaign can be given to
     */
    public function __construct(
        public ?string $campaign,
        public FunnelCounts $counts,
    ) {
    }
}
