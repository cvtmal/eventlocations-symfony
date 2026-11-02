<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Deletes a mail template (`admin` only, decided by the controller). Nothing references one: a sent mail keeps its
 * own copy of the text.
 */
final readonly class DeleteEmailTemplate implements AuditableCommand
{
    public function __construct(
        public string $id,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('email_template', $this->id);
    }

    public function auditSummary(): array
    {
        return ['action' => 'deleted'];
    }
}
