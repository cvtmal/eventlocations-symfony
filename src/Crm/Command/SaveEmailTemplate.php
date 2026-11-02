<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Creates or rewrites a mail template (CRM-10, `admin` only, decided by the controller). The id comes from
 * EntityId::generate() before dispatch for a new one. The summary names the action only, because a name is a value.
 */
final readonly class SaveEmailTemplate implements AuditableCommand
{
    public function __construct(
        public string $id,
        public string $name,
        public string $subject,
        public string $bodyMarkdown,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('email_template', $this->id);
    }

    public function auditSummary(): array
    {
        return ['action' => 'saved'];
    }
}
