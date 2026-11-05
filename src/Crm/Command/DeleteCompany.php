<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Deletes a company (CRM-1, `admin` only, decided by the controller). Its contacts stay without a
 * company, its Eintrag is freed, its tasks and timeline links go, and entries left on no timeline are
 * deleted (D16).
 */
final readonly class DeleteCompany implements AuditableCommand
{
    public function __construct(
        public string $companyId,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('company', $this->companyId);
    }

    public function auditSummary(): array
    {
        return ['action' => 'deleted'];
    }
}
