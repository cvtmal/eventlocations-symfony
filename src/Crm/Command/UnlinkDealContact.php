<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Takes a contact off the deal. The summary names the action only: the audit log outlives an erasure.
 */
final readonly class UnlinkDealContact implements AuditableCommand
{
    public function __construct(
        public string $dealId,
        public string $contactId,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('deal', $this->dealId);
    }

    public function auditSummary(): array
    {
        return ['action' => 'contact_unlinked'];
    }
}
