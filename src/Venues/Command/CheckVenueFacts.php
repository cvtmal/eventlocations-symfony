<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * G12: staff confirm (`checked` true) or withdraw that an entry's facts are checked. With a written description
 * the entry may then be indexed (`Venues\VenueIndexGate`). Staff dispatch it from the Cms; a verified claim (G6,
 * D45) dispatches it nested with `$via` VIA_CLAIM, because the operator confirmed the facts.
 */
final readonly class CheckVenueFacts implements AuditableCommand
{
    public const string VIA_CLAIM = 'claim';

    public function __construct(
        public int $venueNumber,
        public bool $checked,
        public ?string $via = null,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('venue', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        return null === $this->via ? ['facts_checked' => $this->checked] : ['facts_checked' => $this->checked, 'via' => $this->via];
    }
}
