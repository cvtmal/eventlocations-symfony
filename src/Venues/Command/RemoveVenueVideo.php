<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Removes one video of the venue (G15) by its row id (`venue_videos.id`); the others close the gap. Only the venue is
 * purged.
 */
final readonly class RemoveVenueVideo implements AuditableCommand
{
    public function __construct(
        public int $venueNumber,
        public string $videoId,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('venue', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        return ['row_id' => $this->videoId];
    }
}
