<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * What AddVenuePhoto did (#25): the photo's id, and whether the scope already had a photo of
 * the same file (D5: a retried upload answers with its first row and changes no row; the audit
 * middleware still writes its row for the dispatch).
 */
final readonly class AddedPhoto
{
    public function __construct(
        public string $photoId,
        public bool $duplicate,
    ) {
    }
}
