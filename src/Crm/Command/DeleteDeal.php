<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Deletes a deal (`admin` only, decided by the controller). Its contact links, timeline links and tasks go, and
 * entries left on no timeline are deleted. A stage change stays on the company's timeline, with its snapshot.
 */
final readonly class DeleteDeal implements AuditableCommand
{
    public function __construct(
        public string $dealId,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('deal', $this->dealId);
    }

    public function auditSummary(): array
    {
        return ['action' => 'deleted'];
    }
}
