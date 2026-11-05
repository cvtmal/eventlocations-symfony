<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Assigns a venue to a Location as its Eintrag (CRM-1, #56): one Location per venue
 * (VenueAlreadyLinked), one Eintrag per Location (CompanyHasVenue); the same pair again changes
 * nothing.
 */
final readonly class LinkVenue implements AuditableCommand
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
