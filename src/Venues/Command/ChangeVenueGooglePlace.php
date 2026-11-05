<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * The CMS card "Google-Bewertung" (#53): the venue's Google place ID and the switch that hides its Google rating.
 * The audit summary names the changed fields only.
 */
final readonly class ChangeVenueGooglePlace implements AuditableCommand
{
    /**
     * @param list<string> $changedFields
     * @param string|null  $placeId       as typed; the handler trims it and refuses anything but a place ID
     */
    public function __construct(
        public int $venueNumber,
        public string $loadedAt,
        public array $changedFields,
        public ?string $placeId,
        public bool $ratingHidden,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('venue', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        return ['changed' => $this->changedFields];
    }
}
