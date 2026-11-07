<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;
use App\Venues\ValueObject\Grantor;

/**
 * Deletes a photo (#25, D15, D18): its row, then after the commit its variants and, when no
 * row of any venue uses it any more, its original. Also the last gallery photo of a published
 * venue (only publishing needs one). A replaced photo is a removal plus an upload, so it gets
 * a new id (D20).
 *
 * Removing a photo revokes its rights grant (G1): the live grant is ended with `by` as the revoker
 * (the system when the caller names nobody) and the row stays as evidence. This is also the
 * "revoke" action of the staff and manager screens.
 */
final readonly class RemoveVenuePhoto implements AuditableCommand
{
    public function __construct(
        public int $venueNumber,
        public string $photoId,
        public ?Grantor $by = null,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('venue', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        return ['photo_id' => $this->photoId];
    }
}
