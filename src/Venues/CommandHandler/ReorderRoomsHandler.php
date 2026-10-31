<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\ReorderRooms;
use App\Venues\Positions;
use App\Venues\VenueEditing;
use App\Venues\VenueRows;

/**
 * #25, D11, PF11: the list must be exactly the venue's rooms, else StaleEdit; the same order
 * changes nothing. Room order feeds no listing fact, so only the venue is purged.
 */
final class ReorderRoomsHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
        private readonly VenueRows $rows,
        private readonly Positions $positions,
    ) {
    }

    public function __invoke(ReorderRooms $command): void
    {
        $venue = $this->editing->lock($command->venueNumber);
        $ordered = Positions::inOrder($this->rows->rooms($venue), $command->roomIds, $command->venueNumber);
        if (null === $ordered) {
            return;
        }

        $this->positions->renumber($ordered);
        $this->editing->close($venue, purgeListings: false);
    }
}
