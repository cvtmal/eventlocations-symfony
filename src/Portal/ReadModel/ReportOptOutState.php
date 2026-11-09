<?php

declare(strict_types=1);

namespace App\Portal\ReadModel;

/**
 * What the opt-out page of the monthly report (G10) shows: the venue's name and whether its report is off.
 */
final readonly class ReportOptOutState
{
    public function __construct(
        public string $venueName,
        public bool $optedOut,
    ) {
    }
}
