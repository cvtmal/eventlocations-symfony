<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;
use App\Venues\ValueObject\Tier;

/**
 * Sets the paid tier alone, with no form state (#28, D18): a won deal's tier reaches the venue through this, so the CRM
 * cannot race a CMS editor over the capacities `ChangeVenueBasics` writes. The same tier changes nothing.
 */
final readonly class ChangeVenueTier implements AuditableCommand
{
    public function __construct(
        public int $venueNumber,
        public Tier $tier,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('venue', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        return ['changed' => ['tier'], 'tier' => $this->tier->value];
    }
}
