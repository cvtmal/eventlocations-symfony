<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Puts the venue's videos in this order (G15, as D11 for documents). The list must hold exactly the venue's videos
 * (row ids), else StaleEdit.
 */
final readonly class ReorderVenueVideos implements AuditableCommand
{
    /**
     * @param list<string> $videoIds
     */
    public function __construct(
        public int $venueNumber,
        public array $videoIds,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('venue', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        return ['row_ids' => $this->videoIds];
    }
}
