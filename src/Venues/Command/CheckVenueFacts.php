<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * G12: staff confirm (`checked` true) or withdraw that an entry's facts are checked. With a written description
 * the entry may then be indexed (`Venues\VenueIndexGate`). Cms only.
 */
final readonly class CheckVenueFacts implements AuditableCommand
{
    public function __construct(
        public int $venueNumber,
        public bool $checked,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('venue', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        return ['facts_checked' => $this->checked];
    }
}
