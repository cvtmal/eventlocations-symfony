<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Deletes your own timeline entry for good (D14); the audit row records the fact, not the text.
 */
final readonly class DeleteActivity implements AuditableCommand
{
    public function __construct(
        public string $id,
        public string $actingUserId,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('activity', $this->id);
    }

    public function auditSummary(): array
    {
        return ['action' => 'deleted'];
    }
}
