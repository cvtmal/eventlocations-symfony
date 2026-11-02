<?php

declare(strict_types=1);

namespace App\Portal\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Staff send a location back with a reason (#45, POR-13). The reason goes to the manager, not into the audit log.
 */
final readonly class RejectSubmission implements AuditableCommand
{
    public function __construct(
        public int $venueNumber,
        public string $staffUserId,
        public string $reason,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('portal_submission', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        return ['status' => 'rejected'];
    }
}
