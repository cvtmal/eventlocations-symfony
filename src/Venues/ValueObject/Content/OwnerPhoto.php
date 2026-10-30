<?php

declare(strict_types=1);

namespace App\Venues\ValueObject\Content;

/**
 * One gallery photo of a CSV row: a Wix media id as written in the cell (not yet checked
 * against WixPhotoCache::ID_PATTERN) and its alt text, verbatim.
 */
final readonly class OwnerPhoto
{
    public function __construct(
        public string $id,
        public ?string $alt,
    ) {
    }
}
