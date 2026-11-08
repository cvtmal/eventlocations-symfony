<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Shared\Funnel\FunnelEvent;

/**
 * The cohort funnel of the Akquise page: the steps in order and one row per cohort A-D (zeros included).
 */
final readonly class CohortFunnel
{
    /**
     * @param list<FunnelEvent>     $steps
     * @param list<CohortFunnelRow> $rows
     */
    public function __construct(
        public array $steps,
        public array $rows,
    ) {
    }
}
