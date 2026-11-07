<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

use App\Media\ValueObject\Picture;

/**
 * A photo a complaint names (G2), for staff: the thumbnail while the photo exists (held or not), else only the
 * file it was (`originalPath`), like a rights grant whose photo is gone.
 */
final readonly class ModerationCasePhotoView
{
    public function __construct(
        public ?Picture $picture,
        public string $originalPath,
    ) {
    }

    public function exists(): bool
    {
        return null !== $this->picture;
    }
}
