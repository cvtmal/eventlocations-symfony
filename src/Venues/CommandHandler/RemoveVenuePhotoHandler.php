<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\RemoveVenueFiles;
use App\Venues\Command\RemoveVenuePhoto;
use App\Venues\Positions;
use App\Venues\VenueEditing;
use App\Venues\VenueRows;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * #25, D15, D18, PF1: the row goes, its scope closes the gap, and after the commit
 * RemoveVenueFiles deletes the variants and the original, the original only when no photo or
 * document row of any venue uses it (demo venues share originals; one file may sit in the
 * gallery and in a room). The last gallery photo may go, even on a published venue.
 */
final class RemoveVenuePhotoHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
        private readonly VenueRows $rows,
        private readonly Positions $positions,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(RemoveVenuePhoto $command): void
    {
        $venue = $this->editing->lock($command->venueNumber);
        $photo = $this->rows->photo($venue, $command->photoId);
        $room = $photo->room();
        $photoId = $photo->id();
        $originalPath = $photo->originalPath();

        $this->em->remove($photo);
        $this->em->flush();
        $this->positions->renumber($this->rows->photos($venue, $room));

        $this->commandBus->dispatch(
            new RemoveVenueFiles($command->venueNumber, [$photoId], [], $this->rows->isOriginalUsed($originalPath) ? [] : [$originalPath]),
            [new DispatchAfterCurrentBusStamp()],
        );
        $this->editing->close($venue, purgeListings: null === $room);
    }
}
