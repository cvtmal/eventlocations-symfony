<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Moves a photo to the end of a room of its venue, or of the gallery when `roomId` is null
 * (#25, CMS-6). The scope it left closes its gap. No token: the result is a final state.
 */
final readonly class MoveVenuePhotoToRoom implements AuditableCommand
{
    public function __construct(
        public int $venueNumber,
        public string $photoId,
        public ?string $roomId,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('venue', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        return ['photo_id' => $this->photoId, 'room_id' => $this->roomId];
    }
}
