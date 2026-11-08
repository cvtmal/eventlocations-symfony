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
 */
final readonly class RecordSubscriptionPayment implements AuditableCommand
{
    public function __construct(
        public int $venueNumber,
        public string $tierCode,
        public \DateTimeImmutable $startsOn,
        public \DateTimeImmutable $endsOn,
        public ?string $invoiceRef = null,
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
        ];
    }
}
