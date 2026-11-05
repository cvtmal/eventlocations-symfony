<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Removes one contact row from a Location's card (#55, Q10): the row only. The Location's entries,
 * tasks and deals stay.
 */
final readonly class RemoveContact implements AuditableCommand
{
    public function __construct(
        public string $contactId,
        public string $companyId,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('company', $this->companyId);
    }

    public function auditSummary(): array
    {
        return ['contact' => $this->contactId];
    }
}
