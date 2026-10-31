<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\RetitleVenueDocument;
use App\Venues\DocumentTitles;
use App\Venues\Exception\StaleEdit;
use App\Venues\Exception\VenueRowNotFound;
use App\Venues\Exception\VenueRuleViolation;
use App\Venues\RowTokens;
use App\Venues\VenueEditing;
use App\Venues\VenueRows;

/**
 * #25, CMS-7, PF2: the document's own token decides staleness. DocumentTitles writes the
 * titles; nothing changed means no touch and no close(). Only the venue is purged (D19).
 */
final class RetitleVenueDocumentHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
        private readonly VenueRows $rows,
        private readonly DocumentTitles $titles,
    ) {
    }

    /**
     * @throws VenueRowNotFound
     * @throws StaleEdit
     * @throws VenueRuleViolation
     */
    public function __invoke(RetitleVenueDocument $command): void
    {
        $venue = $this->editing->lock($command->venueNumber);
        $document = $this->rows->document($venue, $command->documentId);
        RowTokens::assertFresh($document->updatedAt(), $command->loadedAt, $command->venueNumber);

        if (!$this->titles->write($document, false, $command->titles)) {
            return;
        }

        $document->touch();
        $this->editing->close($venue, purgeListings: false);
    }
}
