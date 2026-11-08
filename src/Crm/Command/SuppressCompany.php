<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Crm\ValueObject\SuppressionReason;
use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Puts a Location on the suppression list for good (G11), with every address it holds now: from the opt-out link of a
 * CRM mail (`OptOut`, no staff member) or when staff take down an objection (`Objection`). A Location already on the
 * list keeps its first reason; the handler answers whether anything was new.
 */
final readonly class SuppressCompany implements AuditableCommand
{
    public function __construct(
        public string $companyId,
        public SuppressionReason $reason,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('company', $this->companyId);
    }

    public function auditSummary(): array
    {
        return ['suppression' => $this->reason->value];
    }
}
