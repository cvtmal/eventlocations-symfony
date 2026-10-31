<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\MoveVenuePhotoToRoom;
use App\Venues\Positions;
use App\Venues\VenueEditing;
use App\Venues\VenueRows;
use Doctrine\ORM\EntityManagerInterface;

/**
 * #25, CMS-6, D11: the photo goes to the end of the target scope, the scope it left closes its
 * gap (moving the cover makes the next gallery photo the cover). The same scope changes
 * nothing. The listings are purged when the gallery is one of the two scopes (D19).
 */
final class MoveVenuePhotoToRoomHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
        private readonly VenueRows $rows,
        private readonly Positions $positions,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(MoveVenuePhotoToRoom $command): void
    {
        $venue = $this->editing->lock($command->venueNumber);
        $photo = $this->rows->photo($venue, $command->photoId);
        $target = null === $command->roomId ? null : $this->rows->room($venue, $command->roomId);
        $source = $photo->room();
        if ($source?->id() === $target?->id()) {
            return;
        }

        $photo->assignTo($target, $this->positions->nextPhoto($venue, $target));
        $this->em->flush();
        $this->positions->renumber($this->rows->photos($venue, $source));

        $this->editing->close($venue, purgeListings: null === $source || null === $target);
    }
}
