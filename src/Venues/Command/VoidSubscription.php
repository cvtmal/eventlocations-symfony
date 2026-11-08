<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Staff void a term recorded by mistake (G9 finish, D42; the CRM's «Zahlung stornieren»), so the venue does not stay
 * on the paid tier for a year. The term row goes (its reminder markers with it); `tierCode`, `startsOn`, `endsOn` and
 * `amountRappen` are the term the staff member saw: they must match the row, and they keep in the venue's audit row what was voided.
 * The handler refuses a term that has already ended (`SubscriptionRefused::ENDED`) or that a later term follows
 * (`LATER`: only the latest goes, so no gap opens), re-syncs `venues.tier_id`, purges
 * the venue and the listings and takes back the term's `pro_paid`.
 */
final readonly class VoidSubscription implements AuditableCommand
{
    public function __construct(
        public int $venueNumber,
        public string $subscriptionId,
        public string $tierCode,
        public \DateTimeImmutable $startsOn,
        public \DateTimeImmutable $endsOn,
        public int $amountRappen,
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
            'voided' => $this->subscriptionId,
            'tier' => $this->tierCode,
            'starts_on' => $this->startsOn->format('Y-m-d'),
            'ends_on' => $this->endsOn->format('Y-m-d'),
            'amount_rappen' => $this->amountRappen,
        ];
    }
}
