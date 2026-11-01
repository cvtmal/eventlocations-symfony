<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Crm\ValueObject\LostReason;
use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Corrects why a lost deal was lost (N9), from the record's stage form; the stage stays. No timeline entry: it is no
 * stage change. The summary names the field only, never a value.
 */
final readonly class ChangeDealLostReason implements AuditableCommand
{
    public function __construct(
        public string $dealId,
        public ?LostReason $lostReason,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('deal', $this->dealId);
    }

    public function auditSummary(): array
    {
        return ['field' => 'lost_reason'];
    }
}
