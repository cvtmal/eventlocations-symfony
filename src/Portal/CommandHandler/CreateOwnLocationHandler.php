<?php

declare(strict_types=1);

namespace App\Portal\CommandHandler;

use App\Cms\ReadModel\VenueEditQuery;
use App\Portal\Command\CreateOwnLocation;
use App\Portal\Entity\PortalVenueAccess;
use App\Portal\Entity\Submission;
use App\Portal\Exception\TooManyUnfinishedLocations;
use App\Portal\Repository\PortalUserRepository;
use App\Portal\Repository\SubmissionRepository;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\CreateVenue;
use App\Venues\Command\RelocateVenue;
use App\Venues\Entity\Venue;
use App\Venues\Exception\VenueNotFound;
use App\Venues\Exception\VenueRuleViolation;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Nests `CreateVenue` and `RelocateVenue` in this command's transaction, so a refused town leaves no venue behind.
 *
 * A nested failure arrives as a wrapped failure inside the one this handler's own dispatch would raise, and
 * `VenueRuleViolation::unwrap()` looks one level deep. Rethrowing it here keeps the rule at depth 1, where the
 * controller finds it. The stale token is read from the database: `updated_at` is TIMESTAMP(0), so the entity can
 * hold a second the database rounded away.
 */
final class CreateOwnLocationHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly SubmissionRepository $submissions,
        private readonly PortalUserRepository $users,
        private readonly VenueEditQuery $edits,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return int the new location's number
     */
    public function __invoke(CreateOwnLocation $command): int
    {
        if ($this->submissions->countUnfinished($command->portalUserId) >= CreateOwnLocation::MAX_UNFINISHED) {
            throw TooManyUnfinishedLocations::forLimit(CreateOwnLocation::MAX_UNFINISHED);
        }

        try {
            $number = (int) $this->commandBus->dispatch(new CreateVenue($command->name, $command->capacityMax))->last(HandledStamp::class)?->getResult();
            $edit = $this->edits->find($number) ?? throw VenueNotFound::forNumber($number);
            $this->commandBus->dispatch(new RelocateVenue($number, $edit->loadedAt, ['town'], $command->town, $command->cantonCode, null, null, null, null, null, null, null, null, null, [], useTownCentre: true));
        } catch (HandlerFailedException $failure) {
            throw VenueRuleViolation::unwrap($failure);
        }

        $now = $this->clock->now();
        $venue = $this->em->getReference(Venue::class, $edit->header->id) ?? throw VenueNotFound::forNumber($number);
        $access = new PortalVenueAccess($this->users->get($command->portalUserId), $venue, $now);
        $access->confirm($now);
        $this->em->persist($access);
        $this->em->persist(new Submission($venue, $now));
        $this->em->flush();

        return $number;
    }
}
