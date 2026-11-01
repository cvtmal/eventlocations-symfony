<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * The one renewal task of a deal (CRM-8, D24), from `app:crm:renewal-tasks`, one transaction per deal. The handler
 * answers whether it wrote one. The id comes from EntityId::generate() before dispatch.
 */
final readonly class CreateRenewalTask implements AuditableCommand
{
    public function __construct(
        public string $id,
        public string $dealId,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('task', $this->id);
    }

    public function auditSummary(): array
    {
        return ['on' => 'deal', 'renewal' => true];
    }
}
