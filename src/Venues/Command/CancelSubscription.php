<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * The manager's "do not renew" (G9, the link of the reminder mail): the term keeps its tier to its last day, the
 * next one is not invoiced and no further reminder goes out. Answers false when the cancellation was already
 * recorded; a term that has ended is refused (`SubscriptionRefused::ended`), a term of another venue is not found.
 */
final readonly class CancelSubscription implements AuditableCommand
{
    public function __construct(
        public int $venueNumber,
        public string $subscriptionId,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('venue', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        return ['changed' => ['subscription'], 'subscription' => $this->subscriptionId];
    }
}
