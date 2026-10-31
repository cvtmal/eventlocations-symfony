<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\RemoveRoom;
use App\Venues\Positions;
use App\Venues\RoomSummary;
use App\Venues\VenueEditing;
use App\Venues\VenueRows;
use Doctrine\ORM\EntityManagerInterface;

/**
 * #25, D14: the room's photos move, in their order, behind the last gallery photo (no file is
 * removed), then the room goes (its translations cascade), the rooms close their gap and the
 * room facts are recomputed. Listings are purged: the gallery and the room facts changed (D19).
 */
final class RemoveRoomHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
        private readonly VenueRows $rows,
        private readonly Positions $positions,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(RemoveRoom $command): void
    {
        $venue = $this->editing->lock($command->venueNumber);
        $room = $this->rows->room($venue, $command->roomId);

        $next = $this->positions->nextPhoto($venue, null);
        foreach ($this->rows->photos($venue, $room) as $photo) {
            $photo->assignTo(null, $next++);
        }
        $this->em->flush();

        $this->em->remove($room);
        $this->em->flush();
        $rooms = $this->rows->rooms($venue);
        $this->positions->renumber($rooms);

        RoomSummary::apply($venue, $rooms);
        $this->editing->close($venue);
    }
}
