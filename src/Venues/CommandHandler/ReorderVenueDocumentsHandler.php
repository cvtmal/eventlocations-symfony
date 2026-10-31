<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\ReorderVenueDocuments;
use App\Venues\Positions;
use App\Venues\VenueEditing;
use App\Venues\VenueRows;

/**
 * #25, D11, D22: the list must be exactly the venue's documents, else StaleEdit; the same order
 * changes nothing. Only the venue is purged (D19).
 */
final class ReorderVenueDocumentsHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
        private readonly VenueRows $rows,
        private readonly Positions $positions,
    ) {
    }

    public function __invoke(ReorderVenueDocuments $command): void
    {
        $venue = $this->editing->lock($command->venueNumber);
        $ordered = Positions::inOrder($this->rows->documents($venue), $command->documentIds, $command->venueNumber);
        if (null === $ordered) {
            return;
        }

        $this->positions->renumber($ordered);
        $this->editing->close($venue, purgeListings: false);
    }
}
