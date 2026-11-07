<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;
use App\Venues\ValueObject\PhotoGrant;

/**
 * Uploads one photo into the gallery (room null) or a room of the venue (#25, CMS-6, D1).
 * `sourceFile` is the uploaded file's temporary path, `extension` comes from its detected
 * type (EXTENSIONS), never from the client's name. The original is stored content-addressed
 * at `cms/<number>/<sha256>.<extension>` under MEDIA_ROOT; the same file again in the same
 * scope returns the existing photo (AddedPhoto::$duplicate).
 *
 * `grant` is set by a caller that can vouch for the file (G1): staff (`staff_attestation`) or a
 * manager whose declaration is on record (`upload`). Without one the photo is stored but not
 * public until a `GrantPhotoRights` covers it. A duplicate keeps the grant state it has.
 */
final readonly class AddVenuePhoto implements AuditableCommand
{
    /** Detected MIME type → the extension of the stored original. */
    public const array EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /** Per scope: the gallery, or one room (#44). */
    public const int MAX_PHOTOS = 30;

    public function __construct(
        public int $venueNumber,
        public ?string $roomId,
        public string $sourceFile,
        public string $extension,
        public ?PhotoGrant $grant = null,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('venue', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        $summary = ['room_id' => $this->roomId, 'type' => $this->extension];

        return null === $this->grant ? $summary : $summary + ['evidence' => $this->grant->evidence->value];
    }
}
