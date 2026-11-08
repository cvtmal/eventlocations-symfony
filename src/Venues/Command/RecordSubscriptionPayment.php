<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Staff recorded the payment of a term (G9, D14; the CRM's «Zahlung erfassen»): a new paid term from `startsOn` to
 * `endsOn`, both days included. A renewal is a term that starts the day after the last one ends. The handler refuses
 * a tier without a price, a term wholly in the past and a term overlapping one already paid (`SubscriptionRefused`), keeps `venues.tier_id`
 * in step and answers the new term's id.
 *
 * `amountRappen` is what was paid, null for the tier's price. 0 grants the term free of charge (a pilot, D41): it then
 * needs a `note` (else `SubscriptionRefused::NOTE`) and records no `pro_paid`.
 */
final readonly class RecordSubscriptionPayment implements AuditableCommand
{
    public function __construct(
        public int $venueNumber,
        public string $tierCode,
        public \DateTimeImmutable $startsOn,
        public \DateTimeImmutable $endsOn,
        public ?string $invoiceRef = null,
        public ?int $amountRappen = null,
        public ?string $note = null,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('venue', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        return [
            'changed' => ['subscription'],
            'tier' => $this->tierCode,
            'starts_on' => $this->startsOn->format('Y-m-d'),
            'ends_on' => $this->endsOn->format('Y-m-d'),
        ] + array_filter(['amount_rappen' => $this->amountRappen, 'note' => $this->note], static fn (mixed $value): bool => null !== $value);
    }
}
