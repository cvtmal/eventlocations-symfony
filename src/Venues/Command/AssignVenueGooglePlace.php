<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Sets a Google place ID a person confirmed in the lookup report (#53, `app:venues:apply-google-places`). It never
 * overwrites a place ID the venue already has: the handler answers false then. The audit summary names the field,
 * never the value.
 */
final readonly class AssignVenueGooglePlace implements AuditableCommand
{
    public function __construct(
        public int $venueNumber,
        public string $placeId,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('venue', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        return ['changed' => ['googlePlaceId']];
    }
}
