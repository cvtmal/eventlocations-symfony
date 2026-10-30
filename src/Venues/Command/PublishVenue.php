<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Puts the venue online (#24, CMS-3, D14). It needs a summary in the default locale, a main building and at least one gallery photo (venue-level, not a room's); a gap is a VenueRuleViolation listing it.
 */
final readonly class PublishVenue implements AuditableCommand
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
        return ['status' => 'published'];
    }
}
