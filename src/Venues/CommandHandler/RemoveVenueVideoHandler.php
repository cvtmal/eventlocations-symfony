<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\RemoveVenueVideo;
use App\Venues\Exception\VenueNotFound;
use App\Venues\Exception\VenueRowNotFound;
use App\Venues\Positions;
use App\Venues\VenueEditing;
use App\Venues\VenueRows;
use Doctrine\ORM\EntityManagerInterface;

/**
 * G15: the row goes and the videos close their gap. Nothing is stored outside the row, so no file to delete. Only the
 * venue is purged.
 */
final class RemoveVenueVideoHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
        private readonly VenueRows $rows,
        private readonly Positions $positions,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @throws VenueNotFound
     * @throws VenueRowNotFound
     */
    public function __invoke(RemoveVenueVideo $command): void
    {
        $venue = $this->editing->lock($command->venueNumber);
        $this->em->remove($this->rows->video($venue, $command->videoId));
        $this->em->flush();
        $this->positions->renumber($this->rows->videos($venue));
        $this->editing->close($venue, purgeListings: false);
    }
}
