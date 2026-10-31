<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Removes a venue from a company (CRM-1). A venue that is not linked to this company is left alone.
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
