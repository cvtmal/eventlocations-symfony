<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Media\MediaPublisher;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\AddVenueDocument;
use App\Venues\DocumentPipeline;
use App\Venues\DocumentTitles;
use App\Venues\Exception\VenueNotFound;
use App\Venues\Exception\VenueRuleViolation;
use App\Venues\Positions;
use App\Venues\PublishedMedia;
use App\Venues\TierLimits;
use App\Venues\VenueEditing;
use App\Venues\VenueOriginals;
use App\Venues\VenueRows;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One PDF upload (#25, CMS-7, D22), composed from #9's DocumentPipeline: titles checked, path
 * `cms/<number>/<sha256>.pdf` (chk_venue_documents_path), lock, the same PDF already there →
 * documentAlreadyUploaded(), store() the original, add() at the next position with its titles,
 * flush, publish() the public copy (revert() on failure), close() without the listings purge
 * (documents are not on the cards, D19).
 *
 * A failure after store() leaves the new original under `cms/<n>/` (content-addressed, the next
 * upload of the same PDF reuses it), as the import and AddVenuePhoto do.
 */
final class AddVenueDocumentHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
        private readonly VenueRows $rows,
        private readonly Positions $positions,
        private readonly DocumentPipeline $documents,
        private readonly DocumentTitles $titles,
        private readonly MediaPublisher $publisher,
        private readonly EntityManagerInterface $em,
        private readonly TierLimits $limits,
    ) {
    }

    /**
     * @return string the new document's id
     *
     * @throws VenueNotFound
     * @throws VenueRuleViolation
     */
    public function __invoke(AddVenueDocument $command): string
    {
        $this->titles->check($command->titles, true);
        $hash = hash_file('sha256', $command->sourceFile);
        $size = filesize($command->sourceFile);
        if (false === $hash || false === $size) {
            throw new \RuntimeException(\sprintf('Cannot read "%s".', $command->sourceFile));
        }
        $path = \sprintf('%s/%s.pdf', VenueOriginals::uploadDirectory($command->venueNumber), $hash);

        $venue = $this->editing->lock($command->venueNumber);
        if (null !== $this->rows->documentWithPath($venue, $path)) {
            throw VenueRuleViolation::documentAlreadyUploaded();
        }
        $this->limits->assertDocumentFits($venue);

        $this->documents->store($command->sourceFile, $path);
        $document = $this->documents->add($venue, $this->positions->nextDocument($venue), $path, $size);
        $this->titles->write($document, true, $command->titles);
        $this->em->flush();
        $published = new PublishedMedia();
        try {
            $this->documents->publish($document, $published);
        } catch (\Throwable $exception) {
            $published->revert($this->publisher);

            throw $exception;
        }

        $this->editing->close($venue, purgeListings: false);

        return $document->id();
    }
}
