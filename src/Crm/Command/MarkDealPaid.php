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
 *
 * `amountRappen` is what was paid, null for the deal's amount (or, without one, the tier's price); 0 records a term
 * free of charge (a pilot, D41), which needs a `note`.
 */
final readonly class MarkDealPaid implements AuditableCommand
{
    public function __construct(
        public string $dealId,
        public string $expected,
        public ?string $invoiceRef = null,
        public ?int $amountRappen = null,
        public ?string $note = null,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('deal', $this->dealId);
    }

    public function auditSummary(): array
    {
        // The amount and the note only when given, so a plain payment's row reads as before.
        return ['expected' => $this->expected, 'invoice_ref' => $this->invoiceRef]
            + array_filter(['amount_rappen' => $this->amountRappen, 'note' => $this->note], static fn (mixed $value): bool => null !== $value);
    }
}
