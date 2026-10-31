<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Marks a done task open again (CRM-5). The controller compares first (P1), as for `CompleteTask`.
 */
final readonly class ReopenTask implements AuditableCommand
{
    public function __construct(
        public string $id,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('task', $this->id);
    }

    public function auditSummary(): array
    {
        return ['action' => 'reopened'];
    }
}
