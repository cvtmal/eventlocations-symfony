<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Staff edit a tier on the screen "Tarife" (F12): everything but its code, which names the row and never changes.
 * The handler answers an unknown code with `TierNotFound` and a name another tier has with `TierNameTaken`, writes
 * nothing when every value is already stored, and clears the HTTP cache otherwise, because the limits and flags shape
 * every venue page of the tier.
 *
 * The summary names the fields that changed (the form's diff), never the values.
 */
final readonly class ReviseTier implements AuditableCommand
{
    /**
     * @param list<string> $changedFields the form's fields that differ from what was loaded
     */
    public function __construct(
        public string $code,
        public string $name,
        public int $priceRappen,
        public bool $vatLine,
        public int $rankWeight,
        public int $photosMax,
        public int $roomsMax,
        public int $videosMax,
        public int $documentsMax,
        public bool $contactVisible,
        public bool $lastMinuteAllowed,
        public bool $detailedReport,
        public ?int $eventTypesMax,
        public array $changedFields,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('tier', $this->code);
    }

    public function auditSummary(): array
    {
        return ['changed' => $this->changedFields];
    }
}
