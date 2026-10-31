<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\AddRoom;
use App\Venues\Entity\Room;
use App\Venues\Exception\VenueNotFound;
use App\Venues\Exception\VenueRuleViolation;
use App\Venues\Positions;
use App\Venues\RoomFields;
use App\Venues\RoomSummary;
use App\Venues\VenueEditing;
use App\Venues\VenueRows;
use Doctrine\ORM\EntityManagerInterface;

/**
 * #25, CMS-5, D23: lock, a new room at the next position with the drawer's fields
 * (RoomFields), flush, the venue's room facts from all its rooms (RoomSummary), close() with
 * the listings purge (room_count, area and height feed the filters, D19).
 */
final class AddRoomHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
        private readonly VenueRows $rows,
        private readonly Positions $positions,
        private readonly RoomFields $fields,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return string the new room's id
     *
     * @throws VenueNotFound
     * @throws VenueRuleViolation
     */
    public function __invoke(AddRoom $command): string
    {
        $venue = $this->editing->lock($command->venueNumber);
        $room = new Room($venue, $this->positions->nextRoom($venue));
        $this->fields->write(
            $room,
            true,
            $command->names,
            $command->descriptions,
            $command->capacityMin,
            $command->capacityMax,
            $command->areaM2,
            $command->heightM,
            $command->floorMaterial,
            $command->seating,
        );
        // after write(): it checks every rule before it persists anything, so a refused room
        // leaves nothing in the unit of work for the next flush of this process
        $this->em->persist($room);
        $this->em->flush();

        RoomSummary::apply($venue, $this->rows->rooms($venue));
        $this->editing->close($venue);

        return $room->id();
    }
}
