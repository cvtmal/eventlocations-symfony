<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Shared\Translation\EnabledLocale;
use App\Venues\Command\PublishLastMinuteOffer;
use App\Venues\EntitlementResolver;
use App\Venues\Entity\LastMinuteOffer;
use App\Venues\Entity\Room;
use App\Venues\Entity\Venue;
use App\Venues\Exception\LastMinuteOfferRefused;
use App\Venues\Exception\VenueNotFound;
use App\Venues\Exception\VenueRuleViolation;
use App\Venues\TierLimits;
use App\Venues\ValueObject\LastMinuteLimits;
use App\Venues\ValueObject\TermDay;
use App\Venues\ValueObject\TermGroup;
use App\Venues\VenueEditing;
use App\Venues\VenueRows;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * G14, D55: lock, the plan (`TierLimits`: Pro, at most three live offers), the dates against the Zurich day, the room
 * and the event types against what the venue's page shows, then persist and close() with the listings purge (the card
 * badge and the page «Kurzfristig frei» change). Every rule is checked before the first persist. No CMS-edit stamp:
 * an offer is outside the content format, so no import can overwrite it.
 */
final class PublishLastMinuteOfferHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
        private readonly VenueRows $rows,
        private readonly TierLimits $limits,
        private readonly EntitlementResolver $entitlements,
        private readonly Connection $connection,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly EnabledLocale $locales,
    ) {
    }

    /**
     * @return string the new offer's id
     *
     * @throws VenueNotFound
     * @throws VenueRuleViolation
     * @throws LastMinuteOfferRefused
     */
    public function __invoke(PublishLastMinuteOffer $command): string
    {
        $venue = $this->editing->lock($command->venueNumber);
        $this->limits->assertLastMinuteOfferFits($venue);

        $now = $this->clock->now();
        $today = TermDay::of($now);
        $startsOn = new \DateTimeImmutable($command->startsOn->format('Y-m-d'));
        $endsOn = new \DateTimeImmutable(($command->endsOn ?? $command->startsOn)->format('Y-m-d'));
        if ($startsOn < $today) {
            throw LastMinuteOfferRefused::startInPast();
        }
        if ($startsOn > $today->modify(\sprintf('+%d days', LastMinuteLimits::HORIZON_DAYS))) {
            throw LastMinuteOfferRefused::tooFarAhead();
        }
        if ($endsOn < $startsOn) {
            throw LastMinuteOfferRefused::endBeforeStart();
        }
        if ($endsOn > $startsOn->modify(\sprintf('+%d days', LastMinuteLimits::MAX_DAYS - 1))) {
            throw LastMinuteOfferRefused::periodTooLong();
        }

        $room = null === $command->roomId ? null : $this->publicRoom($venue, $command->roomId, $now);
        $eventTypes = array_values(array_unique($command->eventTypes));
        if ([] !== array_diff($eventTypes, $this->suitableFor($venue->id()))) {
            throw LastMinuteOfferRefused::eventTypeNotOffered();
        }
        $terms = null === $command->terms ? null : trim($command->terms);

        $offer = new LastMinuteOffer(
            $venue,
            $room,
            $startsOn,
            $endsOn,
            $eventTypes,
            trim($command->priceText),
            $command->minGuests,
            '' === $terms ? null : $terms,
            $now,
        );
        $this->em->persist($offer);
        $this->em->flush();
        $this->editing->close($venue, purgeListings: true, markCmsEdited: false);

        return $offer->id();
    }

    /**
     * One of the rooms the page shows: the first `roomsMax` named ones by position (F12), like `VenueDetailQuery`.
     *
     * @throws LastMinuteOfferRefused
     */
    private function publicRoom(Venue $venue, string $roomId, \DateTimeImmutable $now): Room
    {
        $public = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT r.id FROM rooms r
                WHERE r.venue_id = :venue AND EXISTS (SELECT 1 FROM room_translations rt WHERE rt.room_id = r.id AND rt.locale = :locale)
                ORDER BY r.position
                LIMIT :max
                SQL,
            ['venue' => $venue->id(), 'locale' => $this->locales->default(), 'max' => $this->entitlements->forVenue($venue->id(), $now)->roomsMax],
        );
        if (!\in_array($roomId, $public, true)) {
            throw LastMinuteOfferRefused::foreignRoom();
        }

        return $this->rows->room($venue, $roomId);
    }

    /**
     * @return list<string>
     */
    private function suitableFor(string $venueId): array
    {
        /** @var list<string> $codes */
        $codes = $this->connection->fetchFirstColumn(
            'SELECT term_code FROM venue_terms WHERE venue_id = :id AND term_group = :group',
            ['id' => $venueId, 'group' => TermGroup::SuitableFor->value],
        );

        return $codes;
    }
}
