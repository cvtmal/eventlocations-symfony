<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Factory\RoomFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenuePhotoTranslationFactory;
use App\Factory\VenueRatingHighlightFactory;
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
use App\Venues\Entity\Venue;
use App\Venues\Entity\VenueTranslation;
use App\Venues\ValueObject\PriceLevel;
use App\Venues\ValueObject\RatingHighlight;
use App\Venues\ValueObject\TermGroup;
use App\Venues\ValueObject\Tier;
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
 * Every listing filter, the S6 order, paging, photos and the query count against app_test.
 * Each test runs in a rolled-back transaction.
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
    #[DataProvider('guestBands')]
    public function test_a_guest_band_matches_capacity_max_inside_its_bounds(string $guests, array $expected): void
    {
        foreach ([50, 51, 100, 101, 150, 151, 500] as $max) {
            $this->venue(\sprintf('Max %03d', $max), ['capacityMax' => $max, 'capacityMin' => null]);
        }
        $this->venue('Max 100 Min 90', ['capacityMax' => 100, 'capacityMin' => 90]);

        $this->assertMatches($expected, 'guests='.$guests);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function guestBands(): iterable
    {
        yield 'bis 50' => ['50', ['Max 050']];
        yield 'bis 100' => ['100', ['Max 051', 'Max 100', 'Max 100 Min 90']];
        yield 'bis 150' => ['150', ['Max 101', 'Max 150']];
        yield 'über 150' => ['151', ['Max 151', 'Max 500']];
    }

    public function test_capacity_min_is_ignored_by_the_band(): void
    {
        $this->venue('Gross', ['capacityMax' => 300, 'capacityMin' => 250]);

        $this->assertMatches(['Gross'], 'guests=151');
        $this->assertMatches(['Gross'], 'guests=200');
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
        yield 'infrastructure' => ['infra', TermGroup::Infrastructure, 'wifi', 'air_conditioning'];
        yield 'furniture' => ['furniture', TermGroup::Furniture, 'chairs', 'tables'];
        yield 'technology' => ['tech', TermGroup::Technology, 'sound_system', 'projector_screen'];
        yield 'surroundings' => ['surroundings', TermGroup::Surroundings, 'garden', 'terrace'];
        yield 'ceremony' => ['ceremony', TermGroup::Ceremony, 'chapel', 'free_ceremony'];
        yield 'amenity' => ['amenity', TermGroup::Amenity, 'overnight', 'lake_view'];
    }

    public function test_usage_filters_on_its_one_code(): void
    {
        $this->venue('Exklusiv', terms: [[TermGroup::Usage, 'exclusive']]);
        $this->venue('Mehrere', terms: [[TermGroup::Usage, 'multiple_weddings']]);

        $this->assertMatches(['Exklusiv'], 'usage[]=exclusive');
    }

    public function test_price_levels_combine_with_or(): void
    {
        $this->venue('Moderat', change: static fn (Venue $venue) => $venue->changePriceLevel(PriceLevel::Moderate));
        $this->venue('Mittel', change: static fn (Venue $venue) => $venue->changePriceLevel(PriceLevel::Medium));
        $this->venue('Exklusiv', change: static fn (Venue $venue) => $venue->changePriceLevel(PriceLevel::Exclusive));
        $this->venue('Unbekannt');

        $this->assertMatches(['Mittel'], 'price[]=medium');
        $this->assertMatches(['Mittel', 'Moderat'], 'price[]=medium&price[]=moderate');
    }

    public function test_no_curfew_means_the_venue_said_so(): void
    {
        $this->venue('Ohne', change: static fn (Venue $venue) => $venue->describePartyRules(null, false, null));
        $this->venue('Mit', change: static fn (Venue $venue) => $venue->describePartyRules(null, true, null));
        $this->venue('Unbekannt');

        $this->assertMatches(['Ohne'], 'curfew=none');
    }

    public function test_the_wedding_filters_combine_with_and(): void
    {
        $this->venue('Alles', terms: [[TermGroup::Ceremony, 'chapel'], [TermGroup::Amenity, 'overnight']], change: static function (Venue $venue): void {
            $venue->changePriceLevel(PriceLevel::Medium);
            $venue->describePartyRules(null, false, null);
        });
        $this->venue('Ohne Preis', terms: [[TermGroup::Ceremony, 'chapel'], [TermGroup::Amenity, 'overnight']], change: static fn (Venue $venue) => $venue->describePartyRules(null, false, null));
        $this->venue('Mit Sperrstunde', terms: [[TermGroup::Ceremony, 'chapel'], [TermGroup::Amenity, 'overnight']], change: static function (Venue $venue): void {
            $venue->changePriceLevel(PriceLevel::Medium);
            $venue->describePartyRules(null, true, null);
        });
        $this->venue('Ohne Kapelle', terms: [[TermGroup::Amenity, 'overnight']], change: static function (Venue $venue): void {
            $venue->changePriceLevel(PriceLevel::Medium);
            $venue->describePartyRules(null, false, null);
        });

        $this->assertMatches(['Alles'], 'price[]=medium&curfew=none&ceremony[]=chapel&amenity[]=overnight');
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

    public function test_tier_comes_first(): void
    {
        $tiers = Tier::cases();
        for ($i = 0; $i < 10; ++$i) {
            $this->venue('Venue '.$i, ['tier' => $tiers[$i % \count($tiers)]]);
        }

        $ranks = array_map(static fn (ListingCard $card): int => $card->tier->rank(), $this->query()->results(SearchCriteria::none(), 'de')->cards);

        self::assertCount(10, $ranks);
        $sorted = $ranks;
        sort($sorted);
        self::assertSame($sorted, $ranks);
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
            ->afterInstantiate(static fn (VenueTranslation $translation) => $translation->describeWedding('Romantisch', null, null, null))
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
        $venue = VenueFactory::new()->rated('8.4', 12)->create([
            'name' => 'Seehaus Stäfa',
            'town' => $this->town('Stäfa'),
            'tier' => Tier::Premium,
            'capacityMin' => 20,
            'capacityMax' => 150,
        ]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'slug' => 'seehaus-staefa', 'summary' => 'Am Zürichsee.']);
        VenueRatingHighlightFactory::createOne(['venue' => $venue, 'position' => 2, 'code' => RatingHighlight::Food]);
        VenueRatingHighlightFactory::createOne(['venue' => $venue, 'position' => 1, 'code' => RatingHighlight::Value]);
        $this->venue('Unbewertet');

        $cards = $this->byName($this->query()->results(SearchCriteria::none(), 'de')->cards);
        $card = $cards['Seehaus Stäfa'];

        self::assertSame($venue->id(), $card->id);
        self::assertSame($venue->number(), $card->number);
        self::assertSame('seehaus-staefa', $card->slug);
        self::assertSame(Tier::Premium, $card->tier);
        self::assertSame(20, $card->capacityMin);
        self::assertSame(150, $card->capacityMax);
        self::assertSame('Stäfa', $card->place->townName);
        self::assertSame('Am Zürichsee.', $card->summary);
        self::assertSame(8.4, $card->ratingScore);
        self::assertSame(12, $card->ratingCount);
        self::assertSame([RatingHighlight::Value, RatingHighlight::Food], $card->highlights);
        self::assertSame([], $card->photos);

        self::assertNull($cards['Unbewertet']->ratingScore);
        self::assertNull($cards['Unbewertet']->ratingCount);
        self::assertSame([], $cards['Unbewertet']->highlights);
    }

    public function test_photos_are_the_first_eight_venue_photos(): void
    {
        $venue = $this->venue('Mit Fotos');
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
            $venue = $this->venue('Venue '.$i, ['tier' => Tier::Basic]);
            VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
            VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 2]);
        }
        $paid = $this->venue('Bezahlt', ['tier' => Tier::BeliebteLocation]);
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

    public function test_results_take_three_queries_and_two_for_an_empty_page(): void
    {
        $this->venue('Eins');
        $this->venue('Zwei');
        $query = $this->query();
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);

        $holder->reset();
        $page = $query->results(SearchCriteria::none(), 'de');
        self::assertCount(2, $page->cards);
        self::assertCount(3, $holder->getData()['default'] ?? []);

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

    public function test_cards_by_numbers_take_two_queries(): void
    {
        $first = $this->venue('Eins');
        $second = $this->venue('Zwei');
        $query = $this->query();
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);

        $holder->reset();
        self::assertCount(2, $query->cardsByNumbers([$first->number(), $second->number()], 'de'));
        self::assertCount(2, $holder->getData()['default'] ?? []);
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

        return new ListingQuery($this->connection, new TranslationJoin($locale), $locale, new MockClock($now, 'UTC'));
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
