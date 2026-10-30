<?php

declare(strict_types=1);

namespace App\Venues;

use App\Media\MediaPublisher;
use App\Venues\Entity\Venue;
use App\Venues\Entity\VenueDocument;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Adds a PDF to a venue (#5, #9): store the original under MEDIA_ROOT (before the first DB
 * write) → VenueDocument → flush → publishDocument() to public/media/<number>/<id>.pdf.
 *
 * Callers: the content import (ImportVenueContentHandler) and #25 AddVenueDocument.
 */
final class DocumentPipeline
{
    public function __construct(
        private readonly MediaPublisher $publisher,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function store(string $sourceFile, string $originalPath): void
    {
        $this->publisher->storeOriginal($sourceFile, $originalPath);
    }

    public function add(Venue $venue, int $position, string $originalPath, int $sizeBytes): VenueDocument
    {
        $document = new VenueDocument($venue, $position, $originalPath, $sizeBytes);
        $this->em->persist($document);

        return $document;
    }

    /**
     * Publishes the stored original; the document must be flushed (its venue has a number).
     * The file is tracked in $published before the copy starts.
     */
    public function publish(VenueDocument $document, PublishedMedia $published): void
    {
        $number = $document->venue()->number();
        $published->document($number, $document->id());
        $this->publisher->publishDocument($document->path(), $number, $document->id());
    }
}
