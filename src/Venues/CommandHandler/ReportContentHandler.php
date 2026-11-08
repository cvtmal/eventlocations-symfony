<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\PurgeListingsCache;
use App\Venues\Command\PurgeVenueCache;
use App\Venues\Command\ReportContent;
use App\Venues\Entity\ModerationCase;
use App\Venues\Entity\ModerationCasePhoto;
use App\Venues\Entity\Venue;
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
 * At most `ReportContent::MAX_PHOTOS` named ids count; every public row of the venue that shares an original with a
 * named photo is linked too (M2: the same file in the gallery and in a room). Nothing is linked when the command
 * says not to hold (the site-wide daily budget is spent).
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
        if (null !== $venue && $command->kind->namesPhotos() && $command->hold) {
            foreach ($this->holdable($venue, $command->photoIds) as $photo) {
                $this->em->persist(new ModerationCasePhoto($case, $photo, $now));
                ++$held;
            }
        }
        $this->em->flush();

        if (null !== $venue && $held > 0) {
            $this->commandBus->dispatch(new PurgeVenueCache($venue->number()), [new DispatchAfterCurrentBusStamp()]);
            $this->commandBus->dispatch(new PurgeListingsCache(), [new DispatchAfterCurrentBusStamp()]);
        }

        return $held;
    }

    /**
     * The first `MAX_PHOTOS` named ids that are public photos of the venue, in the order named, then every other
     * public photo of the venue with the same original (one file in the gallery and in a room is two rows).
     *
     * @param list<string> $photoIds
     *
     * @return list<VenuePhoto>
     */
    private function holdable(Venue $venue, array $photoIds): array
    {
        $named = $this->publicOf($venue, 'id', \array_slice(array_values(array_unique($photoIds)), 0, ReportContent::MAX_PHOTOS));
        if ([] === $named) {
            return [];
        }
        $paths = array_values(array_unique(array_map(static fn (VenuePhoto $photo): string => $photo->originalPath(), $named)));

        return array_values($named + $this->publicOf($venue, 'originalPath', $paths));
    }

    /**
     * @param 'id'|'originalPath' $field
     * @param list<string>        $values
     *
     * @return array<string, VenuePhoto> the public photos of the venue whose field is one of the values, by id
     */
    private function publicOf(Venue $venue, string $field, array $values): array
    {
        // Named ids go through the policy first (in their order): one that is no UUID would make Postgres refuse
        // the query. Rows found by their original are filtered after.
        $values = 'id' === $field ? $this->policy->filter($values) : $values;
        if ([] === $values) {
            return [];
        }
        $byId = [];
        foreach ($this->em->getRepository(VenuePhoto::class)->findBy([$field => $values, 'venue' => $venue], ['id' => 'ASC']) as $photo) {
            $byId[$photo->id()] = $photo;
        }
        $photos = [];
        foreach ('id' === $field ? $values : $this->policy->filter(array_keys($byId)) as $id) {
            if (isset($byId[$id])) {
                $photos[$id] = $byId[$id];
            }
        }

        return $photos;
    }
}
