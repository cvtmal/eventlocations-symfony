<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * The owner's reminder to invoice a new location (POR-14, Q20), on its company. The app holds no invoice: this is a
 * to-do and nothing else. The id comes from EntityId::generate() before dispatch.
 */
final readonly class CreateInvoiceTask implements AuditableCommand
{
    public const int DUE_AFTER_DAYS = 30;

    public function __construct(
        public string $id,
        public string $companyId,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('task', $this->id);
    }

    public function auditSummary(): array
    {
        return ['on' => 'company', 'invoice' => true];
    }
}
