<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\ChangeRoom;
use App\Venues\Exception\StaleEdit;
use App\Venues\Exception\VenueRowNotFound;
use App\Venues\Exception\VenueRuleViolation;
use App\Venues\RoomFields;
use App\Venues\RoomSummary;
use App\Venues\RowTokens;
use App\Venues\VenueEditing;
use App\Venues\VenueRows;
use Doctrine\ORM\EntityManagerInterface;

/**
 * #25, CMS-5, D23, PF2: the room's own token decides staleness (a photo upload in the same
 * venue does not stale it). RoomFields writes the drawer's fields; nothing changed means no
 * touch and no close(). Otherwise flush, RoomSummary from all rooms, close() with the
 * listings purge (D19).
 */
final class ChangeRoomHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
        private readonly VenueRows $rows,
        private readonly RoomFields $fields,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @throws VenueRowNotFound
     * @throws StaleEdit
     * @throws VenueRuleViolation
     */
    public function __invoke(ChangeRoom $command): void
    {
        $venue = $this->editing->lock($command->venueNumber);
        $room = $this->rows->room($venue, $command->roomId);
        RowTokens::assertFresh($room->updatedAt(), $command->loadedAt, $command->venueNumber);

        $changed = $this->fields->write(
            $room,
            false,
            $command->names,
            $command->descriptions,
            $command->capacityMin,
            $command->capacityMax,
            $command->areaM2,
            $command->heightM,
            $command->floorMaterial,
            $command->seating,
        );
        if (!$changed) {
            return;
        }
        $this->em->flush();

        RoomSummary::apply($venue, $this->rows->rooms($venue));
        $this->editing->close($venue);
    }
}
