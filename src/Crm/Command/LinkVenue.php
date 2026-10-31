<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Links a venue to a company (CRM-1). A venue belongs to one company at most: linking one that
 * belongs to another company is refused (VenueAlreadyLinked); linking it again to the same company
 * changes nothing.
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
