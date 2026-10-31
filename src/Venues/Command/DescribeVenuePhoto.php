<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Sets a photo's alt texts (#25, D13, D16). Only the locales in `alts` change; an empty text
 * removes that locale's row (a row exists only with a text, the page computes a fallback).
 * Every key must be an enabled locale. `loadedAt` is the photo's row token
 * (RowTokens::sql() of `venue_photos.updated_at`).
 */
final readonly class DescribeVenuePhoto implements AuditableCommand
{
    /**
     * @param array<string, string> $alts locale → alt text
     */
    public function __construct(
        public int $venueNumber,
        public string $photoId,
        public string $loadedAt,
        public array $alts,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('venue', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        return ['photo_id' => $this->photoId, 'locales' => array_keys($this->alts)];
    }
}
