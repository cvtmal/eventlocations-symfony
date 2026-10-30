<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Media\Exception\InvalidMediaPath;
use App\Media\MediaPublisher;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\RemoveVenueFiles;

/**
 * Deletes the files of removed photos and documents and the originals no row uses any more,
 * and a deleted venue's whole public media directory.
 * It runs after the venue's commit, so it is best effort and never throws: a file left
 * behind is harmless, a failure here must not report the committed import as failed.
 */
final class RemoveVenueFilesHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly MediaPublisher $publisher,
    ) {
    }

    public function __invoke(RemoveVenueFiles $command): void
    {
        foreach ($command->photoIds as $photoId) {
            $this->publisher->removePhoto($command->venueNumber, $photoId);
        }
        foreach ($command->documentIds as $documentId) {
            $this->publisher->removeDocument($command->venueNumber, $documentId);
        }
        foreach ($command->originalPaths as $path) {
            try {
                $this->publisher->removeOriginal($path);
            } catch (InvalidMediaPath) {
                // Paths come from rows the import wrote; an invalid one has no file to remove.
            }
        }
        if ($command->wholeVenue) {
            $this->publisher->removeVenue($command->venueNumber);
        }
    }
}
