<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

/**
 * Where a deal stands: its stage, the Zurich day (`Y-m-d`) it was won or lost, and whether it is lost and why.
 */
final readonly class DealStageState
{
    public function __construct(
        public string $stage,
        public ?string $closedOn,
        public bool $isLost,
        public ?string $lostReason,
    ) {
    }
}
