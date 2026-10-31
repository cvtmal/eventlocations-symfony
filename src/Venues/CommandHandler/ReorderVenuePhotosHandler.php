<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\ReorderVenuePhotos;
use App\Venues\Positions;
use App\Venues\VenueEditing;
use App\Venues\VenueRows;

/**
 * #25, D11: the list must be exactly the scope's photos, else StaleEdit (the island showed
 * an older set). The same order again changes nothing. Gallery order feeds the cards, so it
 * purges the listings; a room's order does not (D19).
 */
final class ReorderVenuePhotosHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
        private readonly VenueRows $rows,
        private readonly Positions $positions,
    ) {
    }

    public function __invoke(ReorderVenuePhotos $command): void
    {
        $venue = $this->editing->lock($command->venueNumber);
        $room = null === $command->roomId ? null : $this->rows->room($venue, $command->roomId);

        $ordered = Positions::inOrder($this->rows->photos($venue, $room), $command->photoIds, $command->venueNumber);
        if (null === $ordered) {
            return;
        }

        $this->positions->renumber($ordered);
        $this->editing->close($venue, purgeListings: null === $room);
    }
}
