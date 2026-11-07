<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Media\MediaPublisher;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\AddVenuePhoto;
use App\Venues\Entity\Room;
use App\Venues\Entity\Venue;
use App\Venues\Exception\VenueNotFound;
use App\Venues\Exception\VenueRowNotFound;
use App\Venues\Exception\VenueRuleViolation;
use App\Venues\PhotoGrants;
use App\Venues\PhotoPipeline;
use App\Venues\Positions;
use App\Venues\PublishedMedia;
use App\Venues\ValueObject\AddedPhoto;
use App\Venues\VenueEditing;
use App\Venues\VenueRows;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One photo upload (#25, D1, PF3), composed from #9's PhotoPipeline:
 *
 * 1. hash the file, path `cms/<number>/<sha256>.<ext>` (D4);
 * 2. venue and room exist, the scope has no photo of this file yet (D5) and is below
 *    MAX_PHOTOS (#44), all without a lock;
 * 3. stage(): render the variants and store the original, the CPU-heavy part, outside the
 *    row lock (PF4: doctrine_transaction has already opened the transaction);
 * 4. lock the venue, check room, duplicate and limit again, take the next position, add (with
 *    its grant when the command carries one, G1), flush, publish the variants (revert() on
 *    failure);
 * 5. close(): cms_edited_at, purge the venue, and the listings for a gallery photo (D19).
 *
 * The staging directory goes in every case. A decode failure leaves no new original
 * (PhotoPipeline::stage()).
 */
final class AddVenuePhotoHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
        private readonly VenueRows $rows,
        private readonly Positions $positions,
        private readonly PhotoPipeline $photos,
        private readonly PhotoGrants $grants,
        private readonly MediaPublisher $publisher,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @throws VenueNotFound
     * @throws VenueRowNotFound   when the room is not one of the venue's
     * @throws VenueRuleViolation when the scope already holds MAX_PHOTOS
     */
    public function __invoke(AddVenuePhoto $command): AddedPhoto
    {
        if (!\in_array($command->extension, AddVenuePhoto::EXTENSIONS, true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not an accepted photo type.', $command->extension));
        }
        $hash = hash_file('sha256', $command->sourceFile);
        if (false === $hash) {
            throw new \RuntimeException(\sprintf('Cannot read "%s".', $command->sourceFile));
        }
        $originalPath = \sprintf('cms/%d/%s.%s', $command->venueNumber, $hash, $command->extension);

        $venue = $this->rows->venue($command->venueNumber);
        $room = $this->room($venue, $command->roomId);
        $duplicate = $this->duplicate($venue, $room, $originalPath);
        if (null !== $duplicate) {
            return $duplicate;
        }
        $this->assertRoomForPhoto($venue, $room);

        $staging = $this->photos->openStaging();
        try {
            $staged = $this->photos->stage($command->sourceFile, $originalPath, $staging);

            $venue = $this->editing->lock($command->venueNumber);
            $room = $this->room($venue, $command->roomId);
            $duplicate = $this->duplicate($venue, $room, $originalPath);
            if (null !== $duplicate) {
                return $duplicate;
            }
            $this->assertRoomForPhoto($venue, $room);

            $photo = $this->photos->add($venue, $room, $this->positions->nextPhoto($venue, $room), $staged);
            if (null !== $command->grant) {
                $this->grants->issue($photo, $command->grant);
            }
            $this->em->flush();
            $published = new PublishedMedia();
            try {
                $this->photos->publish($photo, $staged, $published);
            } catch (\Throwable $exception) {
                $published->revert($this->publisher);

                throw $exception;
            }

            $this->editing->close($venue, purgeListings: null === $room);

            return new AddedPhoto($photo->id(), false);
        } finally {
            $this->photos->discardStaging($staging);
        }
    }

    private function room(Venue $venue, ?string $roomId): ?Room
    {
        return null === $roomId ? null : $this->rows->room($venue, $roomId);
    }

    private function duplicate(Venue $venue, ?Room $room, string $originalPath): ?AddedPhoto
    {
        $photo = $this->rows->photoWithOriginal($venue, $room, $originalPath);

        return null === $photo ? null : new AddedPhoto($photo->id(), true);
    }

    private function assertRoomForPhoto(Venue $venue, ?Room $room): void
    {
        if (\count($this->rows->photos($venue, $room)) >= AddVenuePhoto::MAX_PHOTOS) {
            throw VenueRuleViolation::tooManyPhotos();
        }
    }
}
