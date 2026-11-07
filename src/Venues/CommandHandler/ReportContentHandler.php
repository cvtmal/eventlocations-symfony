<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\PurgeListingsCache;
use App\Venues\Command\PurgeVenueCache;
use App\Venues\Command\ReportContent;
use App\Venues\Entity\ModerationCase;
use App\Venues\Entity\ModerationCasePhoto;
use App\Venues\Entity\VenuePhoto;
use App\Venues\PublicPhotoPolicy;
use App\Venues\VenueEditing;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * G2: the hold is the open case itself, so linking a photo hides it; there is no state on the photo. Only ids that
 * the public sees right now (`PublicPhotoPolicy::filter()`) and that are photos of the case's venue are linked: a
 * crafted id of another venue or of a photo nobody granted holds nothing. The venue is locked like every venue
 * write (`VenueEditing::lock()`), so a photo removed at the same moment is either linked or gone, never half.
 *
 * Hiding a photo changes the venue's pages, the cards and the sitemap's images, so both purges follow after the
 * commit. The venue is not closed through `VenueEditing::close()`: a complaint is no edit, and its stamp would
 * freeze the venue against the content import. Answers how many photos were hidden.
 */
final class ReportContentHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
        private readonly PublicPhotoPolicy $policy,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(ReportContent $command): int
    {
        $now = $this->clock->now();
        $venue = null === $command->venueNumber ? null : $this->editing->lock($command->venueNumber);

        $case = new ModerationCase(
            $command->caseId,
            $command->kind,
            $venue,
            $command->venueReference,
            $command->complainantName,
            $command->complainantEmail,
            $command->message,
            $command->sourceUrl,
            $now,
        );
        $this->em->persist($case);

        $held = 0;
        if (null !== $venue && $command->kind->namesPhotos()) {
            $public = $this->policy->filter(array_values(array_unique($command->photoIds)));
            $photos = [] === $public ? [] : $this->em->getRepository(VenuePhoto::class)->findBy(['id' => $public, 'venue' => $venue]);
            $byId = [];
            foreach ($photos as $photo) {
                $byId[$photo->id()] = $photo;
            }
            foreach ($public as $id) {
                if (isset($byId[$id])) {
                    $this->em->persist(new ModerationCasePhoto($case, $byId[$id], $now));
                    ++$held;
                }
            }
        }
        $this->em->flush();

        if (null !== $venue && $held > 0) {
            $this->commandBus->dispatch(new PurgeVenueCache($venue->number()), [new DispatchAfterCurrentBusStamp()]);
            $this->commandBus->dispatch(new PurgeListingsCache(), [new DispatchAfterCurrentBusStamp()]);
        }

        return $held;
    }
}
