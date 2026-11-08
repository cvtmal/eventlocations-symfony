<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Factory\SubscriptionFactory;
use App\Factory\VenueFactory;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\EntitlementResolver;
use App\Venues\Entity\Venue;
use App\Venues\Exception\TierNotFound;
use App\Venues\ValueObject\Entitlements;
use App\Venues\ValueObject\KnownTiers;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The one answer to "which entitlements apply to this venue at this instant" (F12, D15), against the seeded Free and
 * Pro rows. Entitlements are data: every test that edits a tier row proves the next call reads the edit.
 */
final class EntitlementResolverTest extends KernelTestCase
{
    use Factories;
    use RollsBackDatabase;

    private const string TODAY = '2026-11-07 10:00:00';
    private const string LATER = '2031-03-15 23:59:59';

    public function test_a_venue_gets_the_entitlements_of_its_tier_at_any_instant(): void
    {
        $free = $this->venue(KnownTiers::FREE);
        $pro = $this->venue(KnownTiers::PRO);
        $resolver = $this->resolver();

        foreach ([self::TODAY, self::LATER] as $instant) {
            $now = new MockClock($instant)->now();

            self::assertEquals(self::free(), $resolver->forVenue($free->id(), $now), $instant);
            self::assertEquals(self::pro(), $resolver->forVenue($pro->id(), $now), $instant);
        }
    }

    public function test_the_free_row_hides_the_contact_data_and_does_not_rank_while_the_pro_row_does(): void
    {
        $resolver = $this->resolver();

        $free = $resolver->forTier(KnownTiers::FREE);
        $pro = $resolver->forTier(KnownTiers::PRO);

        self::assertSame([false, 0, false], [$free->contactVisible, $free->rankWeight, $free->isPromoted()]);
        self::assertSame([true, 10, true], [$pro->contactVisible, $pro->rankWeight, $pro->isPromoted()]);
    }

    public function test_a_list_gets_one_entry_per_id_in_the_order_asked_for(): void
    {
        $free = $this->venue(KnownTiers::FREE);
        $pro = $this->venue(KnownTiers::PRO);
        $unknown = Uuid::v7()->toRfc4122();

        $all = $this->resolver()->forVenues([$pro->id(), $unknown, $free->id()], new MockClock(self::TODAY)->now());

        self::assertSame([$pro->id(), $unknown, $free->id()], array_keys($all));
        self::assertEquals(self::pro(), $all[$pro->id()]);
        self::assertEquals(self::free(), $all[$free->id()]);
        self::assertEquals(self::free(), $all[$unknown], 'an id that is no venue gets the Free row');
    }

    public function test_an_id_asked_for_twice_is_answered_once(): void
    {
        $pro = $this->venue(KnownTiers::PRO);

        $all = $this->resolver()->forVenues([$pro->id(), $pro->id()], new MockClock(self::TODAY)->now());

        self::assertSame([$pro->id()], array_keys($all));
    }

    public function test_an_empty_list_gives_an_empty_answer_and_runs_no_statement(): void
    {
        $holder = $this->holder();
        $holder->reset();

        self::assertSame([], $this->resolver()->forVenues([], new MockClock(self::TODAY)->now()));
        self::assertCount(0, $holder->getData()['default'] ?? []);
    }

    public function test_a_list_costs_one_statement_however_long_it_is(): void
    {
        $ids = [];
        for ($i = 0; $i < 5; ++$i) {
            $ids[] = $this->venue(0 === $i % 2 ? KnownTiers::FREE : KnownTiers::PRO)->id();
        }
        $holder = $this->holder();
        $holder->reset();

        $all = $this->resolver()->forVenues($ids, new MockClock(self::TODAY)->now());

        self::assertCount(5, $all);
        self::assertCount(1, $holder->getData()['default'] ?? []);
    }

    public function test_a_tier_is_answered_by_its_code(): void
    {
        $resolver = $this->resolver();

        self::assertEquals(self::free(), $resolver->forTier('free'));
        self::assertEquals(self::pro(), $resolver->forTier('pro'));
    }

    public function test_an_unknown_code_is_a_domain_error_that_names_it(): void
    {
        try {
            $this->resolver()->forTier('gold');
            self::fail('The tier does not exist.');
        } catch (TierNotFound $notFound) {
            self::assertSame('gold', $notFound->tierCode);
        }
    }

    public function test_a_missing_free_row_is_an_error_for_an_unknown_venue(): void
    {
        $this->connection->executeStatement("UPDATE tiers SET code = 'gratis' WHERE code = 'free'");

        $this->expectException(TierNotFound::class);

        $this->resolver()->forVenue(Uuid::v7()->toRfc4122(), new MockClock(self::TODAY)->now());
    }

    /**
     * Entitlements are data: an edit of the tier row is what the next call returns, with no cache in between.
     */
    public function test_an_edit_of_a_tier_row_is_read_on_the_next_call(): void
    {
        $free = $this->venue(KnownTiers::FREE);
        $resolver = $this->resolver();
        $now = new MockClock(self::TODAY)->now();
        self::assertSame(6, $resolver->forVenue($free->id(), $now)->photosMax);

        $this->connection->executeStatement("UPDATE tiers SET photos_max = 2, contact_visible = TRUE, event_types_max = NULL, name = 'Gratis' WHERE code = 'free'");

        $after = $resolver->forVenue($free->id(), $now);
        self::assertSame([2, true, null, 'Gratis'], [$after->photosMax, $after->contactVisible, $after->eventTypesMax, $after->tierName]);
        self::assertSame(2, $resolver->forTier(KnownTiers::FREE)->photosMax);
        self::assertSame(2, $resolver->forVenues([$free->id()], $now)[$free->id()]->photosMax);
        self::assertSame(60, $resolver->forTier(KnownTiers::PRO)->photosMax, 'the other row stays as it was');
    }

    public function test_the_term_not_the_venue_s_tier_decides(): void
    {
        $venue = $this->venue(KnownTiers::FREE);
        $resolver = $this->resolver();
        $now = new MockClock(self::TODAY)->now();

        $this->connection->executeStatement("UPDATE venues SET tier_id = (SELECT id FROM tiers WHERE code = 'pro') WHERE id = ?", [$venue->id()]);
        self::assertEquals(self::free(), $resolver->forVenue($venue->id(), $now), 'venues.tier_id is no entitlement (G9)');

        SubscriptionFactory::createOne(['venue' => $venue, 'startsOn' => new \DateTimeImmutable('2026-11-01'), 'endsOn' => new \DateTimeImmutable('2027-10-31')]);
        self::assertEquals(self::pro(), $resolver->forVenue($venue->id(), $now));
        self::assertEquals(self::free(), $resolver->forVenue($venue->id(), new MockClock(self::LATER)->now()), 'a lapsed term gives Free');
    }

    /**
     * One more row is one more plan, with no code behind it (F12): the resolver reads whatever the row says.
     */
    public function test_a_tier_added_later_needs_no_code(): void
    {
        $this->connection->executeStatement("INSERT INTO tiers (id, code, name, price_rappen, rank_weight, photos_max, rooms_max, videos_max, documents_max, contact_visible, last_minute_allowed, detailed_report, event_types_max) VALUES (gen_random_uuid(), 'plus', 'Plus', 9900, 5, 20, 5, 2, 2, TRUE, FALSE, FALSE, 6)");
        $venue = VenueFactory::new()->withoutLocation()->create(['tier' => 'plus']);

        $entitlements = $this->resolver()->forVenue($venue->id(), new MockClock(self::TODAY)->now());

        self::assertEquals(new Entitlements('plus', 'Plus', 5, 20, 5, 2, 2, true, false, false, 6), $entitlements);
        self::assertTrue($entitlements->isPromoted());
        self::assertTrue($entitlements->allowsEventTypes(6));
        self::assertFalse($entitlements->allowsEventTypes(7));
    }

    private function venue(string $tier): Venue
    {
        return VenueFactory::new()->withoutLocation()->create(['tier' => $tier]);
    }

    private function resolver(): EntitlementResolver
    {
        return new EntitlementResolver($this->connection);
    }

    private function holder(): BacktraceDebugDataHolder
    {
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);

        return $holder;
    }

    private static function free(): Entitlements
    {
        return new Entitlements('free', 'Gratis', 0, 6, 1, 1, 1, false, false, false, 3);
    }

    private static function pro(): Entitlements
    {
        return new Entitlements('pro', 'Pro', 10, 60, 20, 5, 5, true, true, true, null);
    }
}
