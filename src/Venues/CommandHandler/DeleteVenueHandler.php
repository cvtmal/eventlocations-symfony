<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\DeleteVenue;
use App\Venues\Command\PurgeListingsCache;
use App\Venues\Command\RemoveVenueFiles;
use App\Venues\Entity\VenueDeletedContentKey;
use App\Venues\Exception\VenueRuleViolation;
use App\Venues\Repository\VenueRepository;
use App\Venues\VenueEditing;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * Deletes an offline venue (#24, D13, P8). Its rows go by ON DELETE CASCADE, inquiries keep
 * their snapshot (`venue_id` SET NULL), and the audit log keeps name and number. A content
 * venue leaves a tombstone of its key, so its folder does not bring it back on the next
 * import.
 *
 * Files go only after the commit (RemoveVenueFiles with DispatchAfterCurrentBusStamp): the
 * public media directory, and only the originals no other venue's row uses (demo venues
 * share theirs).
 */
final class DeleteVenueHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
        private readonly VenueRepository $venues,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(DeleteVenue $command): void
    {
        $venue = $this->editing->lock($command->venueNumber);
        if ($venue->isPublished()) {
            throw VenueRuleViolation::stillPublished();
        }

        $originals = $this->venues->originalsUsedOnlyBy($venue);
        if (null !== $venue->contentKey() && !$this->venues->isContentKeyDeleted($venue->contentKey())) {
            $this->em->persist(new VenueDeletedContentKey($venue->contentKey()));
        }
        $this->em->remove($venue);

        $this->commandBus->dispatch(
            new RemoveVenueFiles($command->venueNumber, [], [], $originals, wholeVenue: true),
            [new DispatchAfterCurrentBusStamp()],
        );
        $this->commandBus->dispatch(new PurgeListingsCache(), [new DispatchAfterCurrentBusStamp()]);
    }
}
