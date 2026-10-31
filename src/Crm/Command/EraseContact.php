<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Erases a contact (CRM-11, D16, `admin` only, decided by the controller): see ContactEraser. The
 * summary holds no value, so the audit log keeps nothing of the person.
 */
final readonly class EraseContact implements AuditableCommand
{
    public function __construct(
        public string $contactId,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('contact', $this->contactId);
    }

    public function auditSummary(): array
    {
        return ['action' => 'erased'];
    }
}
