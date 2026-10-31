<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Puts the venue's rooms in this order (#25, D11). The list must hold exactly the venue's
 * rooms, else StaleEdit. Room order feeds no listing fact, so only the venue is purged (PF11).
 */
final readonly class ReorderRooms implements AuditableCommand
{
    /**
     * @param list<string> $roomIds
     */
    public function __construct(
        public int $venueNumber,
        public array $roomIds,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('venue', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        return ['room_ids' => $this->roomIds];
    }
}
