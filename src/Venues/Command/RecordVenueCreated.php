<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * The audit row of CreateVenue (#24, D9), dispatched by its handler without a stamp so it
 * runs inside CreateVenue's transaction: the venue and its audit row commit or roll back
 * together.
 */
final readonly class RecordVenueCreated implements AuditableCommand
{
    public function __construct(
        public int $venueNumber,
        public string $name,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('venue', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        return ['name' => $this->name];
    }
}
