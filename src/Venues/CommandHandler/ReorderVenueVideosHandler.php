<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\ReorderVenueVideos;
use App\Venues\Positions;
use App\Venues\VenueEditing;
use App\Venues\VenueRows;

/**
 * G15, as for documents (D11): the list must be exactly the venue's videos, else StaleEdit; the same order changes
 * nothing. Only the venue is purged.
 */
final class ReorderVenueVideosHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
        private readonly VenueRows $rows,
        private readonly Positions $positions,
    ) {
    }

    public function __invoke(ReorderVenueVideos $command): void
    {
        $venue = $this->editing->lock($command->venueNumber);
        $ordered = Positions::inOrder($this->rows->videos($venue), $command->videoIds, $command->venueNumber);
        if (null === $ordered) {
            return;
        }
        $this->positions->renumber($ordered);
        $this->editing->close($venue, purgeListings: false);
    }
}
