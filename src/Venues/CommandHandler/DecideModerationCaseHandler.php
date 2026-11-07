<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\DecideModerationCase;
use App\Venues\Command\PublishVenue;
use App\Venues\Command\PurgeListingsCache;
use App\Venues\Command\PurgeVenueCache;
use App\Venues\Command\RemoveVenuePhoto;
use App\Venues\Exception\ModerationCaseAlreadyDecided;
use App\Venues\Exception\VenueRuleViolation;
use App\Venues\Repository\ModerationCaseRepository;
use App\Venues\ValueObject\Grantor;
use App\Venues\ValueObject\ModerationDecision;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * G2: the case is closed first, so the hold ends with this transaction whatever the decision. `remove` nests G1's
 * revoke (`RemoveVenuePhoto` per named photo that still exists: grant ended by staff, photo deleted, files after
 * the commit); a held photo is therefore never public between the two. `keep` puts back online an entry this case
 * took offline (`PublishVenue`; its refusal, e.g. no gallery photo left, rolls the whole decision back and comes
 * out unwrapped as the `VenueRuleViolation` it is). Either way the venue and the listings are purged after the
 * commit, so the pages show the outcome.
 *
 * `PublishVenue` records the funnel step `venue_published` for the republish, as it does for every transition to
 * online (G8): a moderation round-trip is one, and the scorecard counts it like a CMS republish.
 */
final class DecideModerationCaseHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly ModerationCaseRepository $cases,
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(DecideModerationCase $command): void
    {
        $case = $this->cases->get($command->caseId);
        if (!$case->isOpen()) {
            throw ModerationCaseAlreadyDecided::forId($command->caseId);
        }

        $now = $this->clock->now();
        $case->decide($command->decision, $command->note, $command->by, $now);
        $venue = $case->venue();

        if (ModerationDecision::Remove === $command->decision) {
            foreach ($this->cases->photosOf($case) as $row) {
                $photo = $row->photo();
                if (null === $photo) {
                    continue;
                }
                $number = $photo->venue()->number();
                $photoId = $photo->id();
                $row->detach($now);
                $this->commandBus->dispatch(new RemoveVenuePhoto($number, $photoId, Grantor::staff($command->by)));
            }
        }

        if (ModerationDecision::Keep === $command->decision && $case->tookEntryOffline() && null !== $venue) {
            try {
                $this->commandBus->dispatch(new PublishVenue($venue->number()));
            } catch (HandlerFailedException $failure) {
                throw VenueRuleViolation::unwrap($failure);
            }
        }

        if (null !== $venue) {
            $this->commandBus->dispatch(new PurgeVenueCache($venue->number()), [new DispatchAfterCurrentBusStamp()]);
            $this->commandBus->dispatch(new PurgeListingsCache(), [new DispatchAfterCurrentBusStamp()]);
        }
    }
}
