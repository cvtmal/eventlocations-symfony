<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * A hochzeitslocation.ch customer takes back the yes to the import (F14, D29): the entry and the facts copied from
 * it go, the Location goes on the suppression list (no more letters or mails), and its name and notes stay as the
 * proof of what happened. From `app:crm:withdraw-consent`, which deletes the content folder after the commit.
 */
final readonly class WithdrawConsent implements AuditableCommand
{
    public function __construct(
        public string $companyId,
        public int $venueNumber,
        /** The day the customer withdrew, `Y-m-d`. */
        public string $withdrawnOn,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('company', $this->companyId);
    }

    public function auditSummary(): array
    {
        return ['consent' => 'withdrawn', 'venue' => $this->venueNumber];
    }
}
