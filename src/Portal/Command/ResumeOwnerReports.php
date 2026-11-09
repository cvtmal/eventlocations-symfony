<?php

declare(strict_types=1);

namespace App\Portal\Command;

/**
 * Turns a venue's monthly report (G10) on again («wieder abonnieren» on the opt-out page). Idempotent.
 */
final readonly class ResumeOwnerReports
{
    public function __construct(
        public string $venueId,
    ) {
    }
}
