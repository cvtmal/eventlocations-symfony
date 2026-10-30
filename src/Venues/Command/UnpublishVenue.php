<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Takes the venue offline (#24, CMS-3). It needs nothing, so a venue imported without photos (#21) can go offline.
 */
final readonly class UnpublishVenue implements AuditableCommand
{
    public function __construct(
        public int $venueNumber,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('venue', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        return ['status' => 'unpublished'];
    }
}
