<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * «Zahlung erfassen» (G9, D14): the invoice of a won deal is paid, so its venue gets the deal's tier for the deal's
 * contract period (`PaidTerm`). `expected` is the venue's paid-until day (`Y-m-d`, '' when it never paid) the staff
 * member saw; another one under the lock is stale, which is what stops a double click from paying twice. The deal's
 * audit row says who recorded it, the nested `RecordSubscriptionPayment` writes the venue's: one fact each (D19).
 */
final readonly class MarkDealPaid implements AuditableCommand
{
    public function __construct(
        public string $dealId,
        public string $expected,
        public ?string $invoiceRef = null,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('deal', $this->dealId);
    }

    public function auditSummary(): array
    {
        return ['expected' => $this->expected, 'invoice_ref' => $this->invoiceRef];
    }
}
