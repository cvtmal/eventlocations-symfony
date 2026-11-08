<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\OutreachCohort;

/**
 * One cohort's line: its Locations, how many were sent, came back, are suppressed, and the Eintraege per step (in the
 * order of `CohortFunnel::$steps`) since their send day.
 */
final readonly class CohortFunnelRow
{
    /**
     * @param list<int> $steps
     */
    public function __construct(
        public OutreachCohort $cohort,
        public int $locations,
        public int $sent,
        public int $returned,
        public int $suppressed,
        public array $steps,
    ) {
    }
}
