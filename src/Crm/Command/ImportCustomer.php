<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Crm\ValueObject\CustomerRecord;
use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Creates one customer of the owner's sheet as a manual prospect, with its contact and its logged mails
 * (#48 R7). `app:crm:import-customers` dispatches it only for a row classified new (P1). The id comes
 * from EntityId::generate() before dispatch, so the audit row can name the company. The summary holds
 * the sheet rows and the activity count only, never a value from the sheet.
 */
final readonly class ImportCustomer implements AuditableCommand
{
    public function __construct(
        public string $companyId,
        public CustomerRecord $record,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('company', $this->companyId);
    }

    public function auditSummary(): array
    {
        return ['source' => 'kundenliste', 'rows' => $this->record->rows, 'activities' => \count($this->record->mailDates)];
    }
}
