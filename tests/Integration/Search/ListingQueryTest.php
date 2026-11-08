<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Factory\RoomFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenuePhotoTranslationFactory;
use App\Factory\VenueTermFactory;
use App\Factory\VenueTranslationFactory;
use App\Geo\Entity\Town;
use App\Geo\ValueObject\Canton;
use App\Search\ReadModel\KnownTerms;
use App\Search\ReadModel\KnownTermsQuery;
use App\Search\ReadModel\ListingCard;
use App\Search\ReadModel\ListingQuery;
use App\Search\ReadModel\SearchCriteria;
use App\Shared\Translation\EnabledLocale;
use App\Shared\Translation\TranslationJoin;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\EntitlementResolver;
use App\Venues\Entity\Venue;
use App\Venues\Entity\VenueTranslation;
use App\Venues\ValueObject\KnownTiers;
use App\Venues\ValueObject\PriceLevel;
use App\Venues\ValueObject\Seating;
use App\Venues\ValueObject\TermGroup;
use App\Venues\ValueObject\VenuePlace;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

use function Zenstruck\Foundry\Persistence\repository;

/**
 * Every listing filter, the S6 order, paging, photos (cut to the venue's plan, F12) and the query count against
 * app_test. Each test runs in a rolled-back transaction.
 */
final class ListingQueryTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;

    private const NOW = '2026-10-30 10:00:00';

    private EntityManagerInterface $em;
    private Connection $connection;
    private KnownTerms $known;

    protected function setUp(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $this->known = (new KnownTermsQuery($this->connection))->all();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_a_venue_without_a_town_is_a_card_and_is_counted(): void
    {
        $this->venue('Seehaus Stäfa', ['town' => $this->town('Stäfa')]);
        DetailVenues::withoutPlace();
        DetailVenues::inCantonOnly();

        $page = $this->query()->results(SearchCriteria::none(), 'de');

        self::assertSame(3, $page->total);
        self::assertCount(3, $page->cards);
        self::assertArrayHasKey('Wiesenhof Ohne Ort', $this->byName($page->cards));
    }

    public function test_a_card_carries_the_place_it_knows(): void
    {
        $this->venue('Seehaus Stäfa', ['town' => $this->town('Stäfa')]);
        DetailVenues::withoutPlace();
        DetailVenues::inCantonOnly();
        DetailVenues::inRegionOnly($this->em, 'Schweizweit');

        $cards = $this->byName($this->query()->results(SearchCriteria::none(), 'de')->cards);

        self::assertEquals(new VenuePlace('Stäfa', Canton::ZH, null), $cards['Seehaus Stäfa']->place);
        self::assertEquals(new VenuePlace(null, Canton::ZH, null), $cards['Scheune Nur Kanton']->place);
        self::assertEquals(new VenuePlace(null, null, 'Schweizweit'), $cards['Remise Nur Region']->place);
        self::assertEquals(new VenuePlace(null, null, null), $cards['Wiesenhof Ohne Ort']->place);
    }

    public function test_canton(): void
    {
        $this->venue('Seehaus Zürich', ['town' => $this->town('Zürich')]);
        $this->venue('Seehaus Stäfa', ['town' => $this->town('Stäfa')]);
        $this->venue('Landgut Bern', ['town' => $this->town('Bern')]);

        $this->assertMatches(['Seehaus Stäfa', 'Seehaus Zürich'], 'canton=zh');
        $this->assertMatches(['Landgut Bern'], 'canton=be');
        $this->assertMatches([], 'canton=ge');
    }

    public function test_town_with_radius_zero(): void
    {
        $zurich = $this->town('Zürich');
        $this->venue('Seehaus Zürich', ['town' => $zurich]);
        $this->venue('Seehaus Stäfa', ['town' => $this->town('Stäfa')]);

        $this->assertMatches(['Seehaus Zürich'], 'place='.$zurich->id().'&radius=0');
    }

    public function test_zip_with_radius_zero(): void
    {
        $this->venue('Seehaus Zürich', ['town' => $this->town('Zürich')]);
        $this->venue('Seehaus Stäfa', ['town' => $this->town('Stäfa'), 'zip' => '8712']);

        $this->assertMatches(['Seehaus Stäfa'], 'place=8712&radius=0');
    }

    public function test_radius_around_a_town_measures_the_venue_coordinates(): void
    {
        $zurich = $this->town('Zürich');
        $this->venue('Mitte', ['town' => $zurich, 'lat' => $zurich->lat(), 'lng' => $zurich->lng()]);
        $this->venue('Drei Kilometer', ['town' => $zurich, 'lat' => $zurich->lat() + 0.027, 'lng' => $zurich->lng()]);
        $this->venue('Zwanzig Kilometer', ['town' => $zurich, 'lat' => $zurich->lat() + 0.18, 'lng' => $zurich->lng()]);

        $this->assertMatches(['Drei Kilometer', 'Mitte'], 'place='.$zurich->id());
        $this->assertMatches(['Drei Kilometer', 'Mitte'], 'place='.$zurich->id().'&radius=5');
        $this->assertMatches(['Drei Kilometer', 'Mitte', 'Zwanzig Kilometer'], 'place='.$zurich->id().'&radius=25');
        $this->assertMatches(['Drei Kilometer', 'Mitte'], 'place=8001&radius=5');
    }

    #[DataProvider('unknownPlaces')]
    public function test_an_unknown_place_matches_nothing(string $query): void
    {
        $this->venue('Seehaus Zürich', ['town' => $this->town('Zürich')]);

        $this->assertMatches([], $query);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownPlaces(): iterable
    {
        $uuid = Uuid::v7()->toRfc4122();
        yield 'unknown town, radius 0' => ['place='.$uuid.'&radius=0'];
        yield 'unknown town, radius 5' => ['place='.$uuid];
        yield 'unknown ZIP, radius 0' => ['place=0000&radius=0'];
        yield 'unknown ZIP, radius 5' => ['place=0000'];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('guestNumbers')]
    public function test_a_guest_number_matches_the_venues_that_fit_it(string $guests, array $expected): void
    {
        foreach ([50, 100, 150, 500] as $max) {
            $this->venue(\sprintf('Max %03d', $max), ['capacityMax' => $max, 'capacityMin' => null]);
        }
        $this->venue('Max 500 Min 120', ['capacityMax' => 500, 'capacityMin' => 120]);

        $this->assertMatches($expected, 'guests='.$guests);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function guestNumbers(): iterable
    {
        yield '1 fits all but the minimum' => ['1', ['Max 050', 'Max 100', 'Max 150', 'Max 500']];
        yield 'capacity_max is inclusive' => ['50', ['Max 050', 'Max 100', 'Max 150', 'Max 500']];
        yield 'one above a maximum' => ['51', ['Max 100', 'Max 150', 'Max 500']];
        yield 'capacity_min is inclusive' => ['120', ['Max 150', 'Max 500', 'Max 500 Min 120']];
        yield 'above every maximum but one' => ['151', ['Max 500', 'Max 500 Min 120']];
        yield 'nobody fits' => ['501', []];
    }

    public function test_event_types_combine_with_or(): void
    {
        $this->venue('Seminar', terms: [[TermGroup::SuitableFor, 'seminar']]);
        $this->venue('Hochzeit', terms: [[TermGroup::SuitableFor, 'wedding']]);
        $this->venue('Beides', terms: [[TermGroup::SuitableFor, 'seminar'], [TermGroup::SuitableFor, 'wedding']]);
        $this->venue('Apero', terms: [[TermGroup::SuitableFor, 'apero']]);
        $this->venue('Keiner');

        $this->assertMatches(['Beides', 'Seminar'], 'event[]=seminar');
        $this->assertMatches(['Beides', 'Hochzeit', 'Seminar'], 'event[]=seminar&event[]=wedding');
    }

    public function test_event_type_and_guest_number_combine_with_and(): void
    {
        $this->venue('Seminar gross', ['capacityMax' => 200, 'capacityMin' => null], terms: [[TermGroup::SuitableFor, 'seminar']]);
        $this->venue('Seminar klein', ['capacityMax' => 30, 'capacityMin' => null], terms: [[TermGroup::SuitableFor, 'seminar']]);
        $this->venue('Apero gross', ['capacityMax' => 200, 'capacityMin' => null], terms: [[TermGroup::SuitableFor, 'apero']]);

        $this->assertMatches(['Seminar gross'], 'event[]=seminar&guests=80');
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('roomGuestNumbers')]
    public function test_a_guest_number_also_matches_a_venue_whose_room_fits_it(string $guests, array $expected): void
    {
        RoomFactory::createOne(['venue' => $this->venue('Halle 300', ['capacityMax' => 120, 'capacityMin' => null]), 'capacityMin' => null, 'capacityMax' => 300]);
        RoomFactory::createOne(['venue' => $this->venue('Saal ab 200', ['capacityMax' => 50, 'capacityMin' => 20]), 'capacityMin' => 200, 'capacityMax' => 400]);
        RoomFactory::new()->withSeating(Seating::Banquet, null, 250)
            ->create(['venue' => $this->venue('Bankett 250', ['capacityMax' => 100, 'capacityMin' => 60]), 'capacityMin' => null, 'capacityMax' => null]);
        RoomFactory::createOne(['venue' => $this->venue('Raum ohne Max', ['capacityMax' => 100, 'capacityMin' => null]), 'capacityMin' => 500, 'capacityMax' => null]);
        $this->venue('Ohne Raum', ['capacityMax' => 100, 'capacityMin' => null]);

        $this->assertMatches($expected, 'guests='.$guests);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function roomGuestNumbers(): iterable
    {
        yield 'the venue range fits' => ['30', ['Bankett 250', 'Halle 300', 'Ohne Raum', 'Raum ohne Max', 'Saal ab 200']];
        yield 'under a room minimum, above the venue maximum' => ['150', ['Bankett 250', 'Halle 300']];
        yield 'a room maximum is inclusive' => ['300', ['Halle 300', 'Saal ab 200']];
        yield 'a room minimum is inclusive' => ['200', ['Bankett 250', 'Halle 300', 'Saal ab 200']];
        yield 'a room without a maximum fits nobody' => ['500', []];
    }

    public function test_the_card_capacity_spans_the_venue_and_its_rooms(): void
    {
        RoomFactory::createOne(['venue' => $this->venue('Halle', ['capacityMax' => 120, 'capacityMin' => 40]), 'capacityMin' => 10, 'capacityMax' => 300]);
        RoomFactory::createOne(['venue' => $this->venue('Offen', ['capacityMax' => 120, 'capacityMin' => 40]), 'capacityMin' => null, 'capacityMax' => 80]);
        RoomFactory::createOne(['venue' => $this->venue('Klein', ['capacityMax' => 120, 'capacityMin' => 40]), 'capacityMin' => 60, 'capacityMax' => 90]);
        RoomFactory::createOne(['venue' => $this->venue('Ohne Max', ['capacityMax' => 120, 'capacityMin' => 40]), 'capacityMin' => 5, 'capacityMax' => null]);
        $this->venue('Ohne Raum', ['capacityMax' => 120, 'capacityMin' => null]);

        $cards = $this->byName($this->query()->results(SearchCriteria::none(), 'de')->cards);
        $ranges = array_map(static fn (ListingCard $card): array => [$card->capacityMin, $card->capacityMax], $cards);
        ksort($ranges);

        self::assertSame([
            'Halle' => [10, 300],
            'Klein' => [40, 120],
            'Offen' => [null, 120],
            'Ohne Max' => [40, 120],
            'Ohne Raum' => [null, 120],
        ], $ranges);
        $byNumber = $this->query()->cardsByNumbers([$cards['Halle']->number], 'de')[0];
        self::assertSame([10, 300], [$byNumber->capacityMin, $byNumber->capacityMax], 'cardsByNumbers reads the same columns');
    }

    public function test_a_card_carries_its_first_event_type_in_vocabulary_order(): void
    {
        $this->venue('Zwei Anlaesse', terms: [[TermGroup::SuitableFor, 'wedding'], [TermGroup::SuitableFor, 'seminar']]);
        $this->venue('Nur Setting', terms: [[TermGroup::Setting, 'lake']]);
        $this->venue('Keiner');

        $cards = $this->byName($this->query()->results(SearchCriteria::none(), 'de')->cards);

        self::assertSame('seminar', $cards['Zwei Anlaesse']->eventType);
        self::assertNull($cards['Nur Setting']->eventType);
        self::assertNull($cards['Keiner']->eventType);
    }

    #[DataProvider('nameQueries')]
    public function test_name_search_folds_umlauts_and_case(string $q): void
    {
        $this->venue('Gasthof Müller');
        $this->venue('Seehaus Huber');

        $this->assertMatches(['Gasthof Müller'], 'q='.rawurlencode($q));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nameQueries(): iterable
    {
        yield 'German transliteration' => ['mueller'];
        yield 'accent-free' => ['muller'];
        yield 'upper case umlaut' => ['MÜLLER'];
        yield 'two words' => ['gasthof mül'];
    }

    public function test_a_name_query_that_folds_to_nothing_matches_nothing(): void
    {
        $this->venue('Gasthof Müller');

        $this->assertMatches([], 'q='.rawurlencode('日本'));
    }

    public function test_setting_values_combine_with_or(): void
    {
        $this->venue('See', terms: [[TermGroup::Setting, 'lake']]);
        $this->venue('Land', terms: [[TermGroup::Setting, 'countryside']]);
        $this->venue('Stadt', terms: [[TermGroup::Setting, 'city']]);

        $this->assertMatches(['Land', 'See'], 'setting[]=lake&setting[]=countryside');
    }

    public function test_building_values_combine_with_or(): void
    {
        $this->venue('Schloss', terms: [[TermGroup::Building, 'castle']]);
        $this->venue('Scheune', terms: [[TermGroup::Building, 'farm_barn']]);
        $this->venue('Hotel', terms: [[TermGroup::Building, 'hotel']]);

        $this->assertMatches(['Scheune', 'Schloss'], 'building[]=castle&building[]=farm_barn');
    }

    #[DataProvider('andGroups')]
    public function test_property_values_combine_with_and(string $param, TermGroup $group, string $first, string $second): void
    {
        $this->venue('Beides', terms: [[$group, $first], [$group, $second]]);
        $this->venue('Nur eines', terms: [[$group, $first]]);
        $this->venue('Keines');

        $this->assertMatches(['Beides'], \sprintf('%1$s[]=%2$s&%1$s[]=%3$s', $param, $first, $second));
        $this->assertMatches(['Beides', 'Nur eines'], \sprintf('%s[]=%s', $param, $first));
    }

    /**
     * @return iterable<string, array{string, TermGroup, string, string}>
     */
    public static function andGroups(): iterable
    {
        yield 'infrastructure' => ['infra', TermGroup::Infrastructure, 'air_conditioning', 'heating_ventilation'];
        yield 'furniture' => ['furniture', TermGroup::Furniture, 'chairs', 'tables'];
        yield 'technology' => ['tech', TermGroup::Technology, 'wifi', 'projector_screen'];
        yield 'surroundings' => ['surroundings', TermGroup::Surroundings, 'garden', 'terrace'];
        yield 'catering' => ['catering', TermGroup::Catering, 'in_house', 'vegan'];
        yield 'amenity' => ['amenity', TermGroup::Amenity, 'overnight', 'parking'];
    }

    public function test_the_retired_wedding_parameters_filter_nothing(): void
    {
        $this->venue('Mit Preis', terms: [[TermGroup::Amenity, 'dance_floor']], change: static fn (Venue $venue) => $venue->changePriceLevel(PriceLevel::Medium));
        $this->venue('Ohne Preis');

        $this->assertMatches(['Mit Preis', 'Ohne Preis'], 'curfew=none&ceremony[]=chapel&usage[]=exclusive&price[]=medium&amenity[]=dance_floor');
    }

    #[DataProvider('accessThresholds')]
    public function test_access_thresholds(string $access, int $slot, string $limit, string $beyond): void
    {
        $this->venue('Am Limit', distances: $this->distances($slot, $limit));
        $this->venue('Darüber', distances: $this->distances($slot, $beyond));
        $this->venue('Unbekannt');

        $this->assertMatches(['Am Limit'], 'access[]='.$access);
    }

    /**
     * @return iterable<string, array{string, int, string, string}>
     */
    public static function accessThresholds(): iterable
    {
        yield 'station' => ['station', 0, '1.0', '1.1'];
        yield 'highway' => ['highway', 1, '10.0', '10.1'];
        yield 'airport' => ['airport', 2, '30.0', '30.1'];
    }

    public function test_two_access_values_combine_with_and(): void
    {
        $this->venue('Bahn und Autobahn', distances: ['0.5', '5.0', null]);
        $this->venue('Nur Bahn', distances: ['0.5', '50.0', null]);

        $this->assertMatches(['Bahn und Autobahn'], 'access[]=station&access[]=highway');
    }

    public function test_room_facts_are_minimums(): void
    {
        $this->venue('Klein', rooms: [1, 99, '2.49']);
        $this->venue('Mittel', rooms: [2, 100, '2.50']);
        $this->venue('Gross', rooms: [3, 150, '4.00']);
        $this->venue('Ohne Angaben');

        $this->assertMatches(['Gross', 'Mittel'], 'rooms=2');
        $this->assertMatches(['Gross', 'Mittel'], 'area=100');
        $this->assertMatches(['Gross', 'Mittel'], 'height=2.5');
        $this->assertMatches(['Gross'], 'rooms=3&height=2.5');
    }

    public function test_groups_combine_with_and(): void
    {
        $zurich = $this->town('Zürich');
        $this->venue('See Zürich', ['town' => $zurich], [[TermGroup::Setting, 'lake']]);
        $this->venue('See Bern', ['town' => $this->town('Bern')], [[TermGroup::Setting, 'lake']]);
        $this->venue('Stadt Zürich', ['town' => $zurich], [[TermGroup::Setting, 'city']]);

        $this->assertMatches(['See Zürich'], 'canton=zh&setting[]=lake');
    }

    public function test_only_published_venues_with_a_default_translation_are_listed(): void
    {
        $this->venue('Publiziert');
        $this->venue('Entwurf', ['status' => VenueStatus::Unpublished]);
        $untranslated = VenueFactory::createOne(['name' => 'Ohne Deutsch']);
        VenueTranslationFactory::createOne(['venue' => $untranslated, 'locale' => 'fr']);
        VenueFactory::createOne(['name' => 'Ohne Übersetzung']);

        $this->assertMatches(['Publiziert'], '');
    }

    /**
     * F12: the tier's rank weight comes before the daily shuffle, so a Pro entry is listed above a Free one on every
     * day, whatever md5 does to the venue ids.
     */
    public function test_a_pro_venue_comes_before_a_free_one_whatever_the_daily_seed(): void
    {
        $this->tenFreeAndTenPro();

        foreach (['2026-10-30 10:00:00', '2026-11-02 10:00:00', '2026-12-24 10:00:00'] as $now) {
            $promoted = array_map(static fn (ListingCard $card): bool => $card->promoted, $this->query($now)->results(SearchCriteria::none(), 'de')->cards);

            self::assertSame([...array_fill(0, 10, true), ...array_fill(0, 10, false)], $promoted, $now);
        }
    }

    public function test_within_one_tier_the_order_still_changes_with_the_date(): void
    {
        $this->tenFreeAndTenPro();

        $order = fn (string $now, bool $promoted): array => array_values(array_map(
            static fn (ListingCard $card): string => $card->id,
            array_filter($this->query($now)->results(SearchCriteria::none(), 'de')->cards, static fn (ListingCard $card): bool => $card->promoted === $promoted),
        ));

        foreach ([true, false] as $promoted) {
            self::assertCount(10, $order(self::NOW, $promoted));
            self::assertSame($order('2026-10-30 10:00:00', $promoted), $order('2026-10-30 18:00:00', $promoted), 'stable within a day');
            self::assertNotSame($order('2026-10-30 10:00:00', $promoted), $order('2026-11-02 10:00:00', $promoted), 'a new day shuffles the tier again');
        }
    }

    public function test_a_plan_edit_changes_the_order_at_once(): void
    {
        $this->venue('Gratis');
        $this->venue('Bezahlt', ['tier' => KnownTiers::PRO]);
        $names = fn (): array => array_map(static fn (ListingCard $card): string => $card->name, $this->query()->results(SearchCriteria::none(), 'de')->cards);
        self::assertSame(['Bezahlt', 'Gratis'], $names());

        $this->connection->executeStatement("UPDATE tiers SET rank_weight = 0 WHERE code = 'pro'");
        $this->connection->executeStatement("UPDATE tiers SET rank_weight = 5 WHERE code = 'free'");

        self::assertSame(['Gratis', 'Bezahlt'], $names());
        self::assertSame([true, false], array_map(static fn (ListingCard $card): bool => $card->promoted, $this->query()->results(SearchCriteria::none(), 'de')->cards), 'the label follows the weight too');
    }

    public function test_the_order_is_stable_within_a_zurich_day_and_changes_with_the_date(): void
    {
        for ($i = 0; $i < 12; ++$i) {
            $this->venue('Venue '.$i);
        }

        $order = fn (string $now): array => array_map(
            static fn (ListingCard $card): string => $card->id,
            $this->query($now)->results(SearchCriteria::none(), 'de')->cards,
        );

        // 23:30 UTC on 30 October is already 31 October in Zurich (CET, UTC+1).
        self::assertSame($order('2026-10-30 23:30:00'), $order('2026-10-31 10:00:00'));
        self::assertNotSame($order('2026-10-30 22:30:00'), $order('2026-10-30 23:30:00'));
        self::assertNotSame($order('2026-10-30 10:00:00'), $order('2026-11-02 10:00:00'));
        self::assertCount(12, $order(self::NOW));
    }

    public function test_paging(): void
    {
        for ($i = 0; $i < 49; ++$i) {
            $this->venue('Venue '.$i);
        }

        $query = $this->query();
        $first = $query->results(SearchCriteria::none(), 'de');
        $second = $query->results(SearchCriteria::none()->withPage(2), 'de');
        $third = $query->results(SearchCriteria::none()->withPage(3), 'de');

        self::assertCount(48, $first->cards);
        self::assertCount(1, $second->cards);
        self::assertSame(49, $first->total);
        self::assertSame(2, $first->lastPage);
        self::assertSame(48, $first->perPage);
        self::assertSame(2, $second->page);
        $ids = static fn (array $cards): array => array_map(static fn (ListingCard $card): string => $card->id, $cards);
        self::assertSame([], array_intersect($ids($first->cards), $ids($second->cards)));
        self::assertSame([], $third->cards);
        self::assertSame(49, $third->total);
        self::assertSame(2, $third->lastPage);
    }

    public function test_an_empty_result_has_one_last_page(): void
    {
        $page = $this->query()->results(SearchCriteria::none(), 'de');

        self::assertSame([], $page->cards);
        self::assertSame(0, $page->total);
        self::assertSame(1, $page->lastPage);
    }

    public function test_a_card_carries_the_price_level_and_ambience(): void
    {
        $venue = VenueFactory::new()
            ->afterInstantiate(static fn (Venue $venue) => $venue->changePriceLevel(PriceLevel::Exclusive))
            ->create(['name' => 'Schloss Mit Fakten']);
        VenueTranslationFactory::new()
            ->afterInstantiate(static fn (VenueTranslation $translation) => $translation->describeEvent('Romantisch', null, null))
            ->create(['venue' => $venue]);
        $this->venue('Ohne Fakten');

        $cards = $this->byName($this->query()->results(SearchCriteria::none(), 'de')->cards);

        self::assertSame(PriceLevel::Exclusive, $cards['Schloss Mit Fakten']->priceLevel);
        self::assertSame('Romantisch', $cards['Schloss Mit Fakten']->ambience);
        self::assertNull($cards['Ohne Fakten']->priceLevel);
        self::assertNull($cards['Ohne Fakten']->ambience);
    }

    public function test_card_fields(): void
    {
        $venue = VenueFactory::createOne([
            'name' => 'Seehaus Stäfa',
            'town' => $this->town('Stäfa'),
            'tier' => KnownTiers::PRO,
            'capacityMin' => 20,
            'capacityMax' => 150,
        ]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'slug' => 'seehaus-staefa', 'summary' => 'Am Zürichsee.']);

        $cards = $this->byName($this->query()->results(SearchCriteria::none(), 'de')->cards);
        $card = $cards['Seehaus Stäfa'];

        self::assertSame($venue->id(), $card->id);
        self::assertSame($venue->number(), $card->number);
        self::assertSame('seehaus-staefa', $card->slug);
        self::assertTrue($card->promoted);
        self::assertSame(20, $card->capacityMin);
        self::assertSame(150, $card->capacityMax);
        self::assertSame('Stäfa', $card->place->townName);
        self::assertSame('Am Zürichsee.', $card->summary);
        self::assertSame([], $card->photos);
    }

    public function test_photos_are_the_first_eight_venue_photos(): void
    {
        $venue = $this->venue('Mit Fotos', ['tier' => KnownTiers::PRO]);
        $photos = [];
        for ($position = 10; $position >= 1; --$position) {
            $photos[$position] = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => $position]);
        }
        VenuePhotoTranslationFactory::createOne(['photo' => $photos[1], 'alt' => 'Seeterrasse']);
        $room = RoomFactory::createOne(['venue' => $venue]);
        VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $room, 'position' => 1]);

        $card = $this->query()->results(SearchCriteria::none(), 'de')->cards[0];

        self::assertCount(8, $card->photos);
        self::assertSame(range(1, 8), array_map(static fn ($photo): int => $photo->position, $card->photos));
        self::assertSame($photos[1]->id(), $card->photos[0]->photoId);
        self::assertSame($photos[1]->originalPath(), $card->photos[0]->originalPath);
        self::assertSame($venue->number(), $card->photos[0]->venueNumber);
        self::assertSame('Seeterrasse', $card->photos[0]->alt);
        self::assertNull($card->photos[1]->alt);

        $hasMediaColumns = (bool) $this->connection->fetchOne(
            "SELECT count(*) FROM information_schema.columns WHERE table_name = 'venue_photos' AND column_name = 'width'",
        );
        foreach ($card->photos as $photo) {
            if ($hasMediaColumns) {
                // The media cycle's columns are NOT NULL, so every photo carries its variants.
                self::assertNotNull($photo->width);
                self::assertNotNull($photo->height);
                self::assertNotNull($photo->dominantColor);
                self::assertNotNull($photo->variants);
                self::assertTrue($photo->hasVariants());
            } else {
                self::assertNull($photo->width);
                self::assertNull($photo->height);
                self::assertNull($photo->dominantColor);
                self::assertNull($photo->variants);
                self::assertFalse($photo->hasVariants());
            }
        }
    }

    public function test_first_is_the_head_of_the_listing_with_its_own_photo_limit(): void
    {
        for ($i = 0; $i < 6; ++$i) {
            $venue = $this->venue('Venue '.$i, ['tier' => KnownTiers::FREE]);
            VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
            VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 2]);
        }
        $paid = $this->venue('Bezahlt', ['tier' => KnownTiers::PRO]);
        for ($position = 1; $position <= 9; ++$position) {
            VenuePhotoFactory::createOne(['venue' => $paid, 'position' => $position]);
        }

        $query = $this->query();
        $listing = $query->results(SearchCriteria::none(), 'de')->cards;
        $first = $query->first(3, 1, 'de');

        $numbers = static fn (array $cards): array => array_map(static fn (ListingCard $card): int => $card->number, $cards);
        self::assertSame($numbers(\array_slice($listing, 0, 3)), $numbers($first));
        self::assertSame($paid->number(), $first[0]->number);
        foreach ($first as $card) {
            self::assertCount(1, $card->photos);
            self::assertSame(1, $card->photos[0]->position);
        }
        self::assertCount(8, $listing[0]->photos);
    }

    public function test_first_of_nothing_is_an_empty_list(): void
    {
        self::assertSame([], $this->query()->first(3, 1, 'de'));
    }

    /**
     * F12: a card shows no more photos than the venue's plan, wherever the card is built: the listing, the home
     * page's Spotlight and the Merkliste.
     */
    public function test_a_free_card_shows_at_most_six_photos_and_a_pro_card_eight(): void
    {
        $free = $this->venue('Gratis');
        $pro = $this->venue('Bezahlt', ['tier' => KnownTiers::PRO]);
        foreach ([$free, $pro] as $venue) {
            for ($position = 1; $position <= 10; ++$position) {
                VenuePhotoFactory::createOne(['venue' => $venue, 'position' => $position]);
            }
        }
        $query = $this->query();
        $numbers = [$free->number(), $pro->number()];

        $listing = $this->byName($query->results(SearchCriteria::none(), 'de')->cards);
        $spotlight = $this->byName($query->first(2, 8, 'de'));
        $merkliste = $this->byName($query->cardsByNumbers($numbers, 'de'));

        foreach ([$listing, $spotlight, $merkliste] as $cards) {
            self::assertSame(range(1, 6), array_map(static fn ($photo): int => $photo->position, $cards['Gratis']->photos));
            self::assertSame(range(1, 8), array_map(static fn ($photo): int => $photo->position, $cards['Bezahlt']->photos));
        }
    }

    public function test_the_photo_limit_of_the_plan_is_read_on_every_request(): void
    {
        $venue = $this->venue('Gratis');
        for ($position = 1; $position <= 6; ++$position) {
            VenuePhotoFactory::createOne(['venue' => $venue, 'position' => $position]);
        }
        self::assertCount(6, $this->query()->cardsByNumbers([$venue->number()], 'de')[0]->photos);

        $this->connection->executeStatement("UPDATE tiers SET photos_max = 2 WHERE code = 'free'");
        self::assertCount(2, $this->query()->cardsByNumbers([$venue->number()], 'de')[0]->photos);

        $this->connection->executeStatement("UPDATE tiers SET photos_max = 0 WHERE code = 'free'");
        self::assertSame([], $this->query()->cardsByNumbers([$venue->number()], 'de')[0]->photos);
    }

    public function test_results_take_four_queries_and_two_for_an_empty_page(): void
    {
        $this->venue('Eins');
        $this->venue('Zwei');
        $query = $this->query();
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);

        // The cards, their photos, the plans' limits (EntitlementResolver, F12) and the count.
        $holder->reset();
        $page = $query->results(SearchCriteria::none(), 'de');
        self::assertCount(2, $page->cards);
        self::assertCount(4, $holder->getData()['default'] ?? []);

        $holder->reset();
        $empty = $query->results(SearchCriteria::none()->withPage(2), 'de');
        self::assertSame([], $empty->cards);
        self::assertCount(2, $holder->getData()['default'] ?? []);
    }

    public function test_cards_by_numbers_keep_the_given_order(): void
    {
        $first = $this->venue('Erste');
        $second = $this->venue('Zweite');
        $third = $this->venue('Dritte');

        $cards = $this->query()->cardsByNumbers([$third->number(), 2147483647, $first->number(), $second->number()], 'de');

        self::assertSame(['Dritte', 'Erste', 'Zweite'], array_map(static fn (ListingCard $card): string => $card->name, $cards));
    }

    public function test_cards_by_numbers_drop_unpublished_and_untranslated(): void
    {
        $published = $this->venue('Publiziert');
        $draft = $this->venue('Entwurf', ['status' => VenueStatus::Unpublished]);
        $untranslated = VenueFactory::createOne(['name' => 'Ohne Deutsch']);
        VenueTranslationFactory::createOne(['venue' => $untranslated, 'locale' => 'fr']);
        $withoutAny = VenueFactory::createOne(['name' => 'Ohne Übersetzung']);

        $cards = $this->query()->cardsByNumbers([$draft->number(), $untranslated->number(), $withoutAny->number(), $published->number()], 'de');

        self::assertSame([$published->number()], array_map(static fn (ListingCard $card): int => $card->number, $cards));
    }

    public function test_cards_by_numbers_of_nothing_runs_no_query(): void
    {
        $query = $this->query();
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);

        $holder->reset();
        self::assertSame([], $query->cardsByNumbers([], 'de'));
        self::assertCount(0, $holder->getData()['default'] ?? []);
    }

    public function test_cards_by_numbers_take_three_queries(): void
    {
        $first = $this->venue('Eins');
        $second = $this->venue('Zwei');
        $query = $this->query();
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);

        $holder->reset();
        self::assertCount(2, $query->cardsByNumbers([$first->number(), $second->number()], 'de'));
        self::assertCount(3, $holder->getData()['default'] ?? []);
    }

    public function test_cards_by_numbers_carry_their_photos(): void
    {
        $venue = $this->venue('Mit Bildern');
        foreach ([1, 2] as $position) {
            VenuePhotoFactory::createOne(['venue' => $venue, 'position' => $position]);
        }

        $cards = $this->query()->cardsByNumbers([$venue->number()], 'de');

        self::assertCount(2, $cards[0]->photos);
    }

    public function test_cards_by_numbers_take_a_photo_limit(): void
    {
        $venue = $this->venue('Mit drei Bildern');
        foreach ([1, 2, 3] as $position) {
            VenuePhotoFactory::createOne(['venue' => $venue, 'position' => $position]);
        }

        $cards = $this->query()->cardsByNumbers([$venue->number()], 'de', 1);

        self::assertCount(1, $cards[0]->photos);
    }

    /**
     * Ten venues on Free and ten on Pro, created alternately so that no order of creation helps.
     */
    private function tenFreeAndTenPro(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->venue('Free '.$i);
            $this->venue('Pro '.$i, ['tier' => KnownTiers::PRO]);
        }
    }

    /**
     * @param list<string> $expectedNames
     */
    private function assertMatches(array $expectedNames, string $queryString): void
    {
        $criteria = $this->criteria($queryString);
        $query = $this->query();
        $page = $query->results($criteria, 'de');
        $names = array_map(static fn (ListingCard $card): string => $card->name, $page->cards);
        sort($names);

        self::assertSame($expectedNames, $names, $queryString);
        self::assertSame(\count($expectedNames), $query->count($criteria), $queryString);
        self::assertSame($page->total, $query->count($criteria), $queryString);
    }

    private function criteria(string $queryString): SearchCriteria
    {
        parse_str($queryString, $parameters);
        /** @var array<string, mixed> $parameters */
        /** @var InputBag<string> $bag */
        $bag = new InputBag($parameters);

        return SearchCriteria::fromQuery($bag, $this->known);
    }

    private function query(string $now = self::NOW): ListingQuery
    {
        $locale = self::enabledLocale();

        return new ListingQuery($this->connection, new TranslationJoin($locale), $locale, new MockClock($now, 'UTC'), new EntitlementResolver($this->connection));
    }

    /**
     * Built by hand like the query (read models are built by hand in their tests).
     */
    public static function enabledLocale(): EnabledLocale
    {
        $enabled = self::getContainer()->getParameter('kernel.enabled_locales');
        $default = self::getContainer()->getParameter('kernel.default_locale');
        self::assertIsArray($enabled);
        self::assertIsString($default);

        return new EnabledLocale(array_values(array_filter($enabled, \is_string(...))), $default);
    }

    /**
     * A published venue with a German translation.
     *
     * @param array<string, mixed>             $attributes
     * @param list<array{TermGroup, string}>   $terms
     * @param array{?string, ?string, ?string} $distances  station, highway, airport km
     * @param array{int, ?int, ?string}|null   $rooms      room count, largest m², max height
     * @param (callable(Venue): void)|null     $change     applied before the venue is stored
     */
    private function venue(string $name, array $attributes = [], array $terms = [], array $distances = [null, null, null], ?array $rooms = null, ?callable $change = null): Venue
    {
        $factory = VenueFactory::new();
        if (null !== $change) {
            $factory = $factory->afterInstantiate($change(...));
        }
        if ([null, null, null] !== $distances) {
            $factory = $factory->afterInstantiate(static fn (Venue $venue) => $venue->changeDistances($distances[0], null, $distances[1], null, $distances[2], null));
        }
        if (null !== $rooms) {
            $factory = $factory->withRoomFacts(...$rooms);
        }
        $venue = $factory->create(['name' => $name] + $attributes);
        VenueTranslationFactory::createOne(['venue' => $venue]);
        foreach ($terms as [$group, $code]) {
            VenueTermFactory::new()->term($group, $code)->create(['venue' => $venue]);
        }

        return $venue;
    }

    /**
     * @return array{?string, ?string, ?string}
     */
    private function distances(int $slot, string $km): array
    {
        return [0 === $slot ? $km : null, 1 === $slot ? $km : null, 2 === $slot ? $km : null];
    }

    private function town(string $name): Town
    {
        $town = repository(Town::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Town::class, $town);

        return $town;
    }

    /**
     * @param list<ListingCard> $cards
     *
     * @return array<string, ListingCard>
     */
    private function byName(array $cards): array
    {
        return array_column(array_map(static fn (ListingCard $card): array => ['name' => $card->name, 'card' => $card], $cards), 'card', 'name');
    }
}
