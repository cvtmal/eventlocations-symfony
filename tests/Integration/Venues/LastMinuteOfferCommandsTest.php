<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Factory\LastMinuteOfferFactory;
use App\Factory\RoomFactory;
use App\Factory\RoomTranslationFactory;
use App\Factory\VenueFactory;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\Command\EndLastMinuteOffer;
use App\Venues\Command\PublishLastMinuteOffer;
use App\Venues\Entity\Venue;
use App\Venues\Exception\LastMinuteOfferRefused;
use App\Venues\Exception\VenueRowNotFound;
use App\Venues\ValueObject\KnownTiers;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Zenstruck\Foundry\Test\Factories;

/**
 * The write side of «Kurzfristig frei» (G14, D55): Pro only (done-when), no first day before the Zurich day
 * (done-when), at most 90 days ahead and 14 days long, a room and event types the venue's page shows, at most three
 * live offers; every refusal writes nothing. A publish or an end purges the venue and the listings and is audited.
 */
final class LastMinuteOfferCommandsTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use DispatchesVenueCommands;
    use Factories;
    use RollsBackDatabase {
        setUp as rollBackSetUp;
    }

    protected function setUp(): void
    {
        $this->rollBackSetUp();
        self::mockTime('2026-11-08 10:00:00 Europe/Zurich');
    }

    public function test_a_pro_venue_publishes_an_offer_with_every_field(): void
    {
        $venue = $this->venue(KnownTiers::PRO, ['seminar', 'party']);
        $room = RoomFactory::createOne(['venue' => $venue, 'position' => 1]);
        RoomTranslationFactory::createOne(['room' => $room, 'name' => 'Saal']);

        $id = $this->dispatch(new PublishLastMinuteOffer($venue->number(), new \DateTimeImmutable('2026-11-20'), new \DateTimeImmutable('2026-11-22'), $room->id(), ['seminar'], '  Seminarpauschale ab CHF 89  ', 20, '  Nur werktags. '));

        self::assertIsString($id);
        self::assertSame(
            ['room_id' => $room->id(), 'starts_on' => '2026-11-20', 'ends_on' => '2026-11-22', 'event_types' => '["seminar"]', 'price_text' => 'Seminarpauschale ab CHF 89', 'min_guests' => 20, 'terms' => 'Nur werktags.'],
            $this->connection->fetchAssociative('SELECT room_id, starts_on, ends_on, event_types, price_text, min_guests, terms FROM last_minute_offers WHERE id = ?', [$id]),
        );
    }

    public function test_one_day_ends_on_its_first_day_and_empty_terms_are_none(): void
    {
        $venue = $this->venue(KnownTiers::PRO);

        $id = $this->dispatch($this->publish($venue, '2026-11-08', terms: '   '));

        self::assertSame(
            ['starts_on' => '2026-11-08', 'ends_on' => '2026-11-08', 'room_id' => null, 'terms' => null],
            $this->connection->fetchAssociative('SELECT starts_on, ends_on, room_id, terms FROM last_minute_offers WHERE id = ?', [$id]),
            'today is allowed',
        );
    }

    public function test_a_free_venue_cannot_create_one(): void
    {
        $venue = $this->venue(KnownTiers::FREE);

        $violation = $this->violation($this->publish($venue, '2026-11-20'));

        self::assertSame('last_minute.error.not_in_plan', $violation->translationKey());
        self::assertSame(['tier' => 'Free'], $violation->parameters);
        self::assertSame(0, $this->offers($venue));
        self::assertSame([], $this->purger()->venues);
    }

    public function test_a_lapsed_pro_term_cannot_create_one(): void
    {
        $venue = $this->venue(KnownTiers::PRO);
        // G9: the entitlements read the term, `venues.tier_id` follows at night.
        $this->connection->executeStatement("UPDATE subscriptions SET starts_on = '2025-11-08', ends_on = '2026-11-07' WHERE venue_id = ?", [$venue->id()]);

        self::assertSame('last_minute.error.not_in_plan', $this->violation($this->publish($venue, '2026-11-20'))->translationKey());
    }

    public function test_a_first_day_before_today_is_refused(): void
    {
        $venue = $this->venue(KnownTiers::PRO);

        $refusal = $this->refusal($this->publish($venue, '2026-11-07'));

        self::assertSame('last_minute.error.start_in_past', $refusal->translationKey());
        self::assertSame('startsOn', $refusal->field);
        self::assertSame(0, $this->offers($venue));
    }

    /**
     * 23:30 UTC on 8 November is 00:30 on 9 November in Zurich: the 8th is yesterday there.
     */
    public function test_the_past_is_measured_by_the_zurich_day(): void
    {
        self::mockTime('2026-11-08 23:30:00 UTC');
        $venue = $this->venue(KnownTiers::PRO);

        self::assertSame('last_minute.error.start_in_past', $this->refusal($this->publish($venue, '2026-11-08'))->translationKey());
        self::assertIsString($this->dispatch($this->publish($venue, '2026-11-09')));
    }

    public function test_a_first_day_more_than_90_days_ahead_is_refused(): void
    {
        $venue = $this->venue(KnownTiers::PRO);
        $this->dispatch($this->publish($venue, '2027-02-06'));

        $refusal = $this->refusal($this->publish($venue, '2027-02-07'));

        self::assertSame('last_minute.error.too_far_ahead', $refusal->translationKey());
        self::assertSame(['days' => 90], $refusal->parameters);
    }

    public function test_a_period_of_fifteen_days_or_backwards_is_refused(): void
    {
        $venue = $this->venue(KnownTiers::PRO);
        $this->dispatch($this->publish($venue, '2026-11-20', '2026-12-03'));

        $refusal = $this->refusal($this->publish($venue, '2026-11-20', '2026-12-04'));
        self::assertSame('last_minute.error.period_too_long', $refusal->translationKey());
        self::assertSame('endsOn', $refusal->field);
        self::assertSame('last_minute.error.end_before_start', $this->refusal($this->publish($venue, '2026-11-20', '2026-11-19'))->translationKey());
    }

    public function test_the_room_of_another_venue_is_refused(): void
    {
        $venue = $this->venue(KnownTiers::PRO);
        $foreign = RoomFactory::createOne(['venue' => $this->venue(KnownTiers::PRO), 'position' => 1]);
        RoomTranslationFactory::createOne(['room' => $foreign, 'name' => 'Saal']);

        $refusal = $this->refusal($this->publish($venue, '2026-11-20', roomId: $foreign->id()));

        self::assertSame('last_minute.error.foreign_room', $refusal->translationKey());
        self::assertSame('roomId', $refusal->field);
    }

    public function test_a_room_the_page_does_not_show_is_refused(): void
    {
        $venue = $this->venue(KnownTiers::PRO);
        $unnamed = RoomFactory::createOne(['venue' => $venue, 'position' => 1]);

        self::assertSame('last_minute.error.foreign_room', $this->refusal($this->publish($venue, '2026-11-20', roomId: $unnamed->id()))->translationKey());
    }

    public function test_an_event_type_the_venue_does_not_list_is_refused(): void
    {
        $venue = $this->venue(KnownTiers::PRO, ['seminar']);

        $refusal = $this->refusal($this->publish($venue, '2026-11-20', eventTypes: ['seminar', 'wedding']));

        self::assertSame('last_minute.error.event_type_not_offered', $refusal->translationKey());
        self::assertSame('eventTypes', $refusal->field);
    }

    public function test_a_fourth_live_offer_is_refused_and_an_expired_one_does_not_count(): void
    {
        $venue = $this->venue(KnownTiers::PRO);
        LastMinuteOfferFactory::createOne(['venue' => $venue, 'startsOn' => new \DateTimeImmutable('2026-11-01'), 'endsOn' => new \DateTimeImmutable('2026-11-07')]);
        foreach (['2026-11-08', '2026-11-10', '2026-11-12'] as $day) {
            $this->dispatch($this->publish($venue, $day));
        }

        $violation = $this->violation($this->publish($venue, '2026-11-14'));

        self::assertSame('last_minute.error.too_many', $violation->translationKey());
        self::assertSame(['max' => 3], $violation->parameters);
    }

    public function test_a_publish_purges_the_venue_and_the_listings_and_is_audited(): void
    {
        $venue = $this->venue(KnownTiers::PRO);
        $purger = $this->purger();

        $this->dispatch($this->publish($venue, '2026-11-20', '2026-11-21'));

        self::assertSame([$venue->number()], $purger->venues);
        self::assertSame(1, $purger->listings);
        $audit = $this->auditRows($venue->number());
        self::assertCount(1, $audit);
        self::assertSame(PublishLastMinuteOffer::class, $audit[0]['command']);
        self::assertEqualsCanonicalizing(['starts_on' => '2026-11-20', 'ends_on' => '2026-11-21', 'room' => false, 'event_types' => []], $audit[0]['changes']);
        self::assertNull($this->reload($venue)->cmsEditedAt(), 'an offer is outside the content format');
    }

    public function test_ending_deletes_the_offer_purges_and_is_audited(): void
    {
        $venue = $this->venue(KnownTiers::PRO);
        $id = $this->dispatch($this->publish($venue, '2026-11-20'));
        self::assertIsString($id);
        $purger = $this->purger();
        $purger->venues = [];
        $purger->listings = 0;

        $this->dispatch(new EndLastMinuteOffer($venue->number(), $id));

        self::assertSame(0, $this->offers($venue));
        self::assertSame([$venue->number()], $purger->venues);
        self::assertSame(1, $purger->listings);
        self::assertSame(EndLastMinuteOffer::class, $this->auditRows($venue->number())[1]['command']);
    }

    public function test_the_offer_of_another_venue_cannot_be_ended(): void
    {
        $venue = $this->venue(KnownTiers::PRO);
        $other = LastMinuteOfferFactory::createOne(['venue' => $this->venue(KnownTiers::PRO)]);

        try {
            $this->dispatch(new EndLastMinuteOffer($venue->number(), $other->id()));
            self::fail('The command should have failed.');
        } catch (HandlerFailedException $failure) {
            self::assertNotSame([], $failure->getWrappedExceptions(VenueRowNotFound::class));
        }
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM last_minute_offers WHERE id = ?', [$other->id()]));
    }

    /**
     * @param list<string> $suitableFor
     */
    private function venue(string $tier, array $suitableFor = []): Venue
    {
        $venue = VenueFactory::new()->withoutLocation()->create(['tier' => $tier]);
        foreach ($suitableFor as $code) {
            $this->connection->executeStatement("INSERT INTO venue_terms (venue_id, term_group, term_code, is_main) VALUES (?, 'suitable_for', ?, FALSE)", [$venue->id(), $code]);
        }

        return $venue;
    }

    /**
     * @param list<string> $eventTypes
     */
    private function publish(Venue $venue, string $startsOn, ?string $endsOn = null, ?string $roomId = null, array $eventTypes = [], ?string $terms = null): PublishLastMinuteOffer
    {
        return new PublishLastMinuteOffer(
            $venue->number(),
            new \DateTimeImmutable($startsOn),
            null === $endsOn ? null : new \DateTimeImmutable($endsOn),
            $roomId,
            $eventTypes,
            'Ab CHF 89 pro Person',
            null,
            $terms,
        );
    }

    private function refusal(PublishLastMinuteOffer $command): LastMinuteOfferRefused
    {
        try {
            $this->dispatch($command);
        } catch (HandlerFailedException $failure) {
            return LastMinuteOfferRefused::within($failure) ?? throw $failure;
        }

        self::fail('The command should have failed.');
    }

    private function offers(Venue $venue): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM last_minute_offers WHERE venue_id = ?', [$venue->id()]);
    }
}
