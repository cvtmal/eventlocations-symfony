<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Factory\VenueFactory;
use App\Factory\VenueTermFactory;
use App\Factory\VenueTranslationFactory;
use App\Search\ReadModel\FilterDataQuery;
use App\Search\ReadModel\SearchCriteria;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\PriceLevel;
use App\Venues\ValueObject\TermGroup;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * What FilterDataQuery counts as data: published venues only, the Access thresholds, and the
 * two memoized statements. Each test runs in a rolled-back transaction.
 */
final class FilterDataQueryTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;

    private const KEYS = ['place', 'town', 'access.station', 'access.highway', 'access.airport', 'rooms', 'area', 'height', 'price.moderate', 'price.medium', 'price.exclusive'];

    private EntityManagerInterface $em;
    private Connection $connection;
    private FilterDataQuery $query;

    protected function setUp(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $this->query = new FilterDataQuery($this->connection, ListingQueryTest::enabledLocale());
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function keys(): iterable
    {
        foreach (self::KEYS as $key) {
            yield $key => [$key];
        }
    }

    #[DataProvider('keys')]
    public function test_no_venues_means_no_data(string $key): void
    {
        self::assertFalse($this->query->has($key));
    }

    /**
     * @return iterable<string, array{string, string|null, string|null, string|null, bool}>
     */
    public static function distances(): iterable
    {
        yield 'station at the threshold' => ['access.station', '1.0', null, null, true];
        yield 'station beyond it' => ['access.station', '1.1', null, null, false];
        yield 'station unknown' => ['access.station', null, '1.0', '1.0', false];
        yield 'highway at the threshold' => ['access.highway', null, '10.0', null, true];
        yield 'highway beyond it' => ['access.highway', null, '10.1', null, false];
        yield 'airport at the threshold' => ['access.airport', null, null, '30.0', true];
        yield 'airport beyond it' => ['access.airport', null, null, '30.1', false];
    }

    #[DataProvider('distances')]
    public function test_access_means_a_venue_within_the_threshold(string $key, ?string $station, ?string $highway, ?string $airport, bool $expected): void
    {
        $this->venue(static fn (Venue $venue) => $venue->changeDistances($station, null === $station ? null : 'Bahnhof', $highway, null, $airport, null));

        self::assertSame($expected, $this->query->has($key));
    }

    public function test_no_rooms_is_no_data_for_rooms(): void
    {
        $this->venue(static fn (Venue $venue) => $venue->summariseRooms(0, null, null));

        self::assertFalse($this->query->has('rooms'));
    }

    public function test_room_facts_answer_separately(): void
    {
        $this->venue(static fn (Venue $venue) => $venue->summariseRooms(1, null, null));

        self::assertTrue($this->query->has('rooms'));
        self::assertFalse($this->query->has('area'));
        self::assertFalse($this->query->has('height'));

        $this->venue(static fn (Venue $venue) => $venue->summariseRooms(1, 120, null));
        $this->venue(static fn (Venue $venue) => $venue->summariseRooms(1, null, '3.5'));
        $this->query->reset();

        self::assertTrue($this->query->has('area'));
        self::assertTrue($this->query->has('height'));
    }

    public function test_price_levels_answer_separately(): void
    {
        $this->venue(static fn (Venue $venue) => $venue->changePriceLevel(PriceLevel::Medium));

        self::assertTrue($this->query->has('price.medium'));
        self::assertFalse($this->query->has('price.moderate'));
        self::assertFalse($this->query->has('price.exclusive'));
    }

    public function test_a_code_outside_filter_codes_never_shows(): void
    {
        $venue = $this->venue();
        foreach (['tent', 'overnight', 'wheelchair_access'] as $code) {
            VenueTermFactory::new()->term(TermGroup::Amenity, $code)->create(['venue' => $venue]);
        }

        self::assertSame(['overnight', 'wheelchair_access'], $this->query->terms(TermGroup::Amenity));
    }

    public function test_an_active_listed_code_without_data_is_kept(): void
    {
        self::assertSame(['lake_view'], $this->query->terms(TermGroup::Amenity, ['lake_view']));
    }

    public function test_amenities_come_in_vocabulary_order(): void
    {
        $venue = $this->venue();
        foreach (['wheelchair_access', 'dance_floor', 'overnight', 'panorama'] as $code) {
            VenueTermFactory::new()->term(TermGroup::Amenity, $code)->create(['venue' => $venue]);
        }

        self::assertSame(['overnight', 'panorama', 'dance_floor', 'wheelchair_access'], $this->query->terms(TermGroup::Amenity));
    }

    public function test_filter_codes_exist_in_the_vocabulary(): void
    {
        foreach (SearchCriteria::FILTER_CODES as $group => $codes) {
            $known = $this->connection->fetchFirstColumn('SELECT code FROM taxonomy_terms WHERE term_group = ?', [$group]);

            self::assertSame([], array_values(array_diff($codes, $known)), $group);
        }
    }

    public function test_an_unpublished_venue_counts_for_nothing(): void
    {
        $venue = VenueFactory::new()->withRoomFacts(2, 120, '3.5')->create(['status' => VenueStatus::Unpublished]);
        VenueTranslationFactory::createOne(['venue' => $venue]);
        VenueTermFactory::new()->term(TermGroup::Setting, 'lake')->create(['venue' => $venue]);

        self::assertFalse($this->query->has('rooms'));
        self::assertSame([], $this->query->terms(TermGroup::Setting));
    }

    public function test_a_venue_without_a_default_locale_translation_counts_for_nothing(): void
    {
        $venue = VenueFactory::new()->withRoomFacts(2, 120, '3.5')->create();
        VenueTermFactory::new()->term(TermGroup::Setting, 'lake')->create(['venue' => $venue]);

        self::assertFalse($this->query->has('rooms'));
        self::assertSame([], $this->query->terms(TermGroup::Setting));
    }

    public function test_published_venues_without_coordinates_are_no_data(): void
    {
        DetailVenues::withoutPlace();
        DetailVenues::inCantonOnly();

        self::assertFalse($this->query->has('place'));
        self::assertFalse($this->query->has('town'));
    }

    public function test_coordinates_without_a_town_are_place_data_but_no_town(): void
    {
        DetailVenues::inCantonWithPin('47.3769', '8.5417');

        self::assertTrue($this->query->has('place'));
        self::assertFalse($this->query->has('town'));
    }

    public function test_an_unpublished_venue_with_a_town_is_no_data(): void
    {
        VenueTranslationFactory::createOne(['venue' => VenueFactory::createOne(['town' => DetailVenues::town('Bern'), 'status' => VenueStatus::Unpublished])]);

        self::assertFalse($this->query->has('place'));
    }

    public function test_a_published_venue_with_a_town_is_data(): void
    {
        DetailVenues::minimal();

        self::assertTrue($this->query->has('place'));
        self::assertTrue($this->query->has('town'));
    }

    public function test_a_second_call_runs_no_query_and_reset_asks_again(): void
    {
        $holder = $this->holder();
        self::assertFalse($this->query->has('place'));
        DetailVenues::minimal();

        $holder->reset();
        self::assertFalse($this->query->has('place'));
        self::assertCount(0, $holder->getData()['default'] ?? []);

        $this->query->reset();
        self::assertTrue($this->query->has('place'));
    }

    public function test_all_keys_take_one_query_and_reset_asks_again(): void
    {
        $holder = $this->holder();

        $holder->reset();
        foreach (self::KEYS as $key) {
            self::assertFalse($this->query->has($key));
        }
        self::assertCount(1, $holder->getData()['default'] ?? []);

        $this->venue(static fn (Venue $venue) => $venue->summariseRooms(1, null, null));
        $holder->reset();
        self::assertFalse($this->query->has('rooms'), 'The answer is memoized.');
        self::assertCount(0, $holder->getData()['default'] ?? []);

        $this->query->reset();
        self::assertTrue($this->query->has('rooms'));
        self::assertCount(1, $holder->getData()['default'] ?? []);
    }

    public function test_terms_without_data_are_left_out(): void
    {
        $this->venue();

        self::assertSame([], $this->query->terms(TermGroup::Setting));
        self::assertSame([], $this->query->terms(TermGroup::Building));
    }

    public function test_terms_come_in_vocabulary_order(): void
    {
        $venue = $this->venue();
        foreach (['cafe', 'brewery_winery', 'castle', 'hotel'] as $code) {
            VenueTermFactory::new()->term(TermGroup::Building, $code)->create(['venue' => $venue]);
        }

        self::assertSame(['hotel', 'castle', 'brewery_winery', 'cafe'], $this->query->terms(TermGroup::Building));
    }

    public function test_an_active_code_without_data_is_kept_in_vocabulary_order(): void
    {
        VenueTermFactory::new()->term(TermGroup::Building, 'hotel')->create(['venue' => $this->venue()]);

        self::assertSame(['bar_club', 'hotel', 'cafe'], $this->query->terms(TermGroup::Building, ['cafe', 'bar_club']));
    }

    public function test_all_groups_take_one_query_and_reset_asks_again(): void
    {
        $holder = $this->holder();

        $holder->reset();
        self::assertSame([], $this->query->terms(TermGroup::Setting));
        self::assertSame([], $this->query->terms(TermGroup::Infrastructure));
        self::assertSame([], $this->query->terms(TermGroup::Surroundings));
        self::assertCount(1, $holder->getData()['default'] ?? []);

        VenueTermFactory::new()->term(TermGroup::Setting, 'lake')->create(['venue' => $this->venue()]);
        self::assertSame([], $this->query->terms(TermGroup::Setting), 'The answer is memoized.');

        $this->query->reset();
        self::assertSame(['lake'], $this->query->terms(TermGroup::Setting));
    }

    public function test_a_group_that_is_no_listing_filter_has_no_terms(): void
    {
        $venue = $this->venue();
        VenueTermFactory::new()->term(TermGroup::Language, 'de')->create(['venue' => $venue]);
        VenueTermFactory::new()->term(TermGroup::Catering, 'buffet')->create(['venue' => $venue]);
        VenueTermFactory::new()->term(TermGroup::SuitableFor, 'seminar')->create(['venue' => $venue]);

        foreach ([[TermGroup::Language, 'de'], [TermGroup::Catering, 'buffet'], [TermGroup::SuitableFor, 'seminar']] as [$group, $code]) {
            self::assertSame([], $this->query->terms($group), $group->value);
            self::assertSame([], $this->query->terms($group, [$code]), $group->value);
        }
    }

    public function test_an_unknown_filter_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown filter "rooom".');

        $this->query->has('rooom');
    }

    /**
     * A published venue with a default-locale translation.
     *
     * @param (callable(Venue): void)|null $change applied before the venue is stored
     */
    private function venue(?callable $change = null): Venue
    {
        $factory = VenueFactory::new();
        $venue = (null === $change ? $factory : $factory->afterInstantiate($change(...)))->create();
        VenueTranslationFactory::createOne(['venue' => $venue]);

        return $venue;
    }

    private function holder(): BacktraceDebugDataHolder
    {
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);

        return $holder;
    }
}
