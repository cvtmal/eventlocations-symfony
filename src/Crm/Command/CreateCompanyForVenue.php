<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Creates the company of a venue that has none, with its contact person as a `venue_contact` (CRM-1,
 * D19). Behind the bootstrap and the venue panel's "Firma anlegen". The id comes from
 * EntityId::generate() before dispatch, so the audit row can name the company.
 */
final readonly class CreateCompanyForVenue implements AuditableCommand
{
    public function __construct(
        public string $venueId,
        public string $companyId,
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
