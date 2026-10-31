<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Crm\ValueObject\RecordKind;
use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * A timeline entry by hand (CRM-4, D12): `kind` and `recordId` name the record (lead's D1, so #28 adds a case and
 * no argument). The id comes from EntityId::generate() before dispatch, so the caller can read the entry back.
 * The summary holds the type only, never the text, because an erasure cannot reach the audit log (D16).
 */
final readonly class LogActivity implements AuditableCommand
{
    public function __construct(
        public string $id,
        public RecordKind $kind,
        public string $recordId,
        public string $type,
        public ?string $body,
        public \DateTimeImmutable $occurredAt,
        public string $actingUserId,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('activity', $this->id);
    }

    public function auditSummary(): array
    {
        return ['type' => $this->type];
    }
}
