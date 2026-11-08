<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Crm\ValueObject\Outreach;
use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Sets the outreach fields of a Location (G11) as a whole, from its "Akquise" tab. The summary names no value: a
 * campaign code is harmless, but the dates and the basis say something about the person behind a Location.
 */
final readonly class ChangeOutreach implements AuditableCommand
{
    public function __construct(
        public string $companyId,
        public Outreach $outreach,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('company', $this->companyId);
    }

    public function auditSummary(): array
    {
        return ['field' => 'outreach'];
    }
}
