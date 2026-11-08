<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Sets the listing tier alone, with no form state (#28, D18): a won deal's tier reaches the venue through this, so the
 * CRM cannot race a CMS editor over the capacities `ChangeVenueBasics` writes. The same tier changes nothing. The tier
 * is named by its code (`tiers.code`); the handler refuses an unknown one with `TierNotFound`.
 */
final readonly class ChangeVenueTier implements AuditableCommand
{
    public function __construct(
        public int $venueNumber,
        public string $tierCode,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('venue', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        return ['changed' => ['tier'], 'tier' => $this->tierCode];
    }
}
