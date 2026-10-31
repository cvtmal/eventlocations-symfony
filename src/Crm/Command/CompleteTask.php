<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Marks a task done (CRM-5). The controller compares first (P1), because the audit middleware writes a row for
 * every dispatch, a no-op included.
 */
final readonly class CompleteTask implements AuditableCommand
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
        return ['action' => 'completed'];
    }
}
