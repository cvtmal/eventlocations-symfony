<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Ends an offer «Kurzfristig frei» before its last day (G14): the row is deleted. An offer is never edited; a manager
 * ends it and publishes a new one (D55).
 */
final readonly class EndLastMinuteOffer implements AuditableCommand
{
    public function __construct(
        public int $venueNumber,
        public string $offerId,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('venue', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        return ['offer_id' => $this->offerId];
    }
}
