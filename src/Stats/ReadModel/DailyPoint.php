<?php

declare(strict_types=1);

namespace App\Stats\ReadModel;

/**
 * One day of a statistics chart (#47); days without a `stats_daily` row carry 0.
 */
final readonly class DailyPoint
{
    /**
     * @param \DateTimeImmutable $day midnight of the Zurich day
     */
    public function __construct(
        public \DateTimeImmutable $day,
        public int $value,
    ) {
    }
}
