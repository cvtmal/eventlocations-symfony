<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Crm\ValueObject\LostReason;
use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Moves a deal to another stage (CRM-7). `expected` is the stage the card or the form showed: under the lock the same
 * target is a no-op, even for two people who chose it, and any other stage than the expected one is stale (D17-b).
 * The audit row is the middleware's; the timeline entry is the handler's, in the same transaction (D17-d).
 */
final readonly class MoveDealStage implements AuditableCommand
{
    public function __construct(
        public string $dealId,
        public string $stage,
        public string $expected,
        public string $actingUserId,
        public ?LostReason $lostReason = null,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('deal', $this->dealId);
    }

    public function auditSummary(): array
    {
        return ['from' => $this->expected, 'to' => $this->stage];
    }
}
