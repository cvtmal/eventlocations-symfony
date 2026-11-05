<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Releases a Location's Eintrag (CRM-1, #56). A venue that is not this Location's Eintrag is left alone.
 */
final readonly class UnlinkVenue implements AuditableCommand
{
    public function __construct(
        public string $companyId,
        public string $venueId,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('company', $this->companyId);
    }

    public function auditSummary(): array
    {
        return ['venue' => $this->venueId];
    }
}
