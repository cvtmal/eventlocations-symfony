<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Crm\ValueObject\SuppressionReason;
use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Puts one address on the suppression list for good (G11): someone asked by reply or phone not to be written to again.
 * The audit row never holds the address (an erasure cannot reach the audit log); its subject is the list.
 */
final readonly class SuppressEmail implements AuditableCommand
{
    public function __construct(
        public string $email,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('suppression', 'email');
    }

    public function auditSummary(): array
    {
        return ['suppression' => SuppressionReason::Objection->value];
    }
}
