<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;
use App\Venues\ValueObject\Tier;

/**
 * "Tier auf Location anwenden" (CRM-8): a won deal's tier goes to its venue. `expected` is the venue's tier the staff
 * member saw; another one under the lock is stale. The deal's audit row says who applied it, the nested
 * `ChangeVenueTier` writes the venue's: one fact each (D19).
 */
final readonly class ApplyDealTier implements AuditableCommand
{
    public function __construct(
        public string $dealId,
        public Tier $expected,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('deal', $this->dealId);
    }

    public function auditSummary(): array
    {
        return ['expected' => $this->expected->value];
    }
}
