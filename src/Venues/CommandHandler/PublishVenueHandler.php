<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Command\RecordFunnelEvent;
use App\Shared\Funnel\FunnelEvent;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\PublishVenue;
use App\Venues\Exception\VenueRuleViolation;
use App\Venues\ReadModel\VenuePublishGaps;
use App\Venues\VenueEditing;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * CMS-3, D14: a venue goes online only with a summary in the default locale, a main
 * building and at least one gallery photo (`room_id IS NULL`; a room's photo is not on the
 * card). Every gap is named at once. Publishing an online venue changes nothing.
 *
 * The answer says whether this was the first publish of a venue CreateVenue made (Q20). The caller then fires
 * NewVenuePublished through VenueEvents, after the commit, because a handler cannot run code after its own.
 *
 * Every transition to online (the first publish or a republish) is a funnel step (G8): `venue_published` is
 * recorded after the commit, and not at all when this command fails or its surrounding command rolls back.
 */
final class PublishVenueHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
        private readonly VenuePublishGaps $gaps,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    /**
     * @return bool whether this was the first publish of a venue CreateVenue made
     */
    public function __invoke(PublishVenue $command): bool
    {
        $venue = $this->editing->lock($command->venueNumber);
        if ($venue->isPublished()) {
            return false;
        }

        $missing = $this->gaps->missing($venue->id());
        if ([] !== $missing) {
            throw VenueRuleViolation::notPublishable($missing);
        }

        $first = $venue->awaitsFirstPublish();
        $venue->publish();
        $this->commandBus->dispatch(new RecordFunnelEvent(FunnelEvent::VenuePublished, $venue->id()), [new DispatchAfterCurrentBusStamp()]);
        $this->editing->close($venue);

        return $first;
    }
}
