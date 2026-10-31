<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Deletes a room (#25, D14). Its photos are not lost: they move, in their order, to the end of
 * the gallery, because the FK would cascade them away with their files. The venue's room facts
 * are recomputed.
 */
final readonly class RemoveRoom implements AuditableCommand
{
    public function __construct(
        public int $venueNumber,
        public string $roomId,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('venue', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        return ['room_id' => $this->roomId];
    }
}
