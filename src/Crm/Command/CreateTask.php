<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Crm\ValueObject\RecordKind;
use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * A new to-do (CRM-5): from a record (`kind` and `recordId`, lead's D1, so #28 adds a case and no argument) or from
 * the task list, which names no record. The id comes from EntityId::generate() before dispatch. The summary names
 * the record's kind only, never the title, because an erasure cannot reach the audit log (D16).
 */
final readonly class CreateTask implements AuditableCommand
{
    public function __construct(
        public string $id,
        public string $title,
        public \DateTimeImmutable $dueOn,
        public string $assigneeId,
        public string $actingUserId,
        public ?RecordKind $kind = null,
        public ?string $recordId = null,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('task', $this->id);
    }

    public function auditSummary(): array
    {
        return ['on' => $this->kind?->value];
    }
}
