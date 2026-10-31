<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Puts the photos of the gallery (room null) or of one room in this order (#25, D11). The
 * list must hold exactly the scope's photos; any other list is a StaleEdit (the island
 * showed an older state). Position 1 of the gallery is the cover (D12).
 */
final readonly class ReorderVenuePhotos implements AuditableCommand
{
    /**
     * @param list<string> $photoIds
     */
    public function __construct(
        public int $venueNumber,
        public ?string $roomId,
        public array $photoIds,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('venue', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        return ['room_id' => $this->roomId, 'photo_ids' => $this->photoIds];
    }
}
