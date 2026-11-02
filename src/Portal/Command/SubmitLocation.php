<?php

declare(strict_types=1);

namespace App\Portal\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * "Veröffentlichen" (#45, POR-12): the location goes to review and the manager confirms the rights in one step. It
 * is also the way back online. The handler answers whether the review really started: a second press fires nothing.
 */
final readonly class SubmitLocation implements AuditableCommand
{
    public function __construct(
        public string $portalUserId,
        public int $venueNumber,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('portal_submission', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        return ['status' => 'submitted'];
    }
}
