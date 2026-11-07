<?php

declare(strict_types=1);

namespace App\Stats\ReadModel;

/**
 * One ISO week of the funnel scorecard (G8).
 */
final readonly class FunnelWeek
{
    /**
     * @param \DateTimeImmutable $start Monday 00:00 Europe/Zurich
     */
    public function __construct(
        public \DateTimeImmutable $start,
        public FunnelCounts $counts,
    ) {
    }
}
