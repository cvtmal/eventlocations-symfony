<?php

declare(strict_types=1);

namespace App\Venues;

use App\Media\MediaPublisher;

/**
 * The public files one write published (photo variants, PDFs, a new venue's directory), so
 * a failure of that write can remove them again (revert()) before its transaction rolls
 * back. Each file is tracked before its copy starts, so a half-copied file goes too.
 *
 * Callers: the content import and #25's AddVenuePhoto / AddVenueDocument, one instance per
 * write.
 */
final class PublishedMedia
{
    /** @var list<array{int, string}> venue number, photo id */
    private array $photos = [];

    /** @var list<array{int, string}> venue number, document id */
    private array $documents = [];

    /** @var list<int> */
    private array $newVenues = [];

    public function photo(int $venueNumber, string $photoId): void
    {
        $this->photos[] = [$venueNumber, $photoId];
    }

    public function document(int $venueNumber, string $documentId): void
    {
        $this->documents[] = [$venueNumber, $documentId];
    }

    /**
     * A venue created by this write: its whole public directory holds only this write's
     * files (a number is never reused).
     */
    public function newVenue(int $venueNumber): void
    {
        $this->newVenues[] = $venueNumber;
    }

    public function revert(MediaPublisher $publisher): void
    {
        foreach ($this->photos as [$venueNumber, $photoId]) {
            $publisher->removePhoto($venueNumber, $photoId);
        }
        foreach ($this->documents as [$venueNumber, $documentId]) {
            $publisher->removeDocument($venueNumber, $documentId);
        }
        foreach ($this->newVenues as $venueNumber) {
            $publisher->removeVenue($venueNumber);
        }
    }
}
