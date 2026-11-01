<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * "Verlängern" (CRM-8, D21): copies a won deal into the next period as a new deal at `new`. `id` is the new deal's, from
 * EntityId::generate() before dispatch, so the caller can redirect to its record.
 */
final readonly class RenewDeal implements AuditableCommand
{
    public function __construct(
        public string $id,
        public string $dealId,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('deal', $this->id);
    }

    public function auditSummary(): array
    {
        return ['renewed_from' => $this->dealId];
    }
}
