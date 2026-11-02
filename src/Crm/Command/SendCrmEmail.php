<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Crm\ValueObject\RecordKind;
use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * A staff member's mail from a record (CRM-10). `contactId` picks the recipient on a company, null for the company's
 * own address. The id comes from EntityId::generate() before dispatch, so the caller can read the entry back.
 * A failed send is audited too, because the attempt happened. The summary holds the type only, never the subject or
 * the text, because an erasure cannot reach the audit log (D16).
 */
final readonly class SendCrmEmail implements AuditableCommand
{
    public function __construct(
        public string $id,
        public RecordKind $kind,
        public string $recordId,
        public ?string $contactId,
        public ?string $cc,
        public string $subject,
        public string $body,
        public string $actingUserId,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('activity', $this->id);
    }

    public function auditSummary(): array
    {
        return ['type' => 'email_sent'];
    }
}
