<?php

declare(strict_types=1);

namespace App\Venues\Command;

/**
 * Delete the files of photos and documents an import removed, and originals no row uses
 * any more. Dispatched with DispatchAfterCurrentBusStamp, so it runs only after the
 * venue's transaction committed. `wholeVenue` (DeleteVenue, #24) also removes the venue's
 * public media directory.
 */
final readonly class RemoveVenueFiles
{
    /**
     * @param list<string> $photoIds      variants under public/media/<number>/<id>-*
     * @param list<string> $documentIds   public/media/<number>/<id>.pdf
     * @param list<string> $originalPaths relative to MEDIA_ROOT
     * @param bool         $wholeVenue    also remove public/media/<number>/ (the venue was deleted)
     */
    public function __construct(
        public int $venueNumber,
        public array $photoIds,
        public array $documentIds,
        public array $originalPaths,
        public bool $wholeVenue = false,
    ) {
    }
}
