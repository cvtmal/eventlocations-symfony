<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Factory\VenueFactory;
use App\Factory\VenueSlugHistoryFactory;
use App\Factory\VenueTermFactory;
use App\Factory\VenueTranslationFactory;
use App\Geo\ValueObject\Canton;
use App\Media\ValueObject\Picture;
use App\Shared\Translation\TranslationJoin;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Integration\Search\ListingQueryTest;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\ReadModel\DetailFactGroup;
use App\Venues\ReadModel\DetailSeating;
use App\Venues\ReadModel\VenueDetailQuery;
use App\Venues\ValueObject\Access;
use App\Venues\ValueObject\FloorMaterial;
use App\Venues\ValueObject\OwnCatering;
use App\Venues\ValueObject\PriceLevel;
use App\Venues\ValueObject\Seating;
use App\Venues\ValueObject\Term;
use App\Venues\ValueObject\TermGroup;
use App\Venues\ValueObject\Tier;
use App\Venues\ValueObject\VenuePlace;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Zenstruck\Foundry\Test\Factories;

final class VenueDetailQueryTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;

    private EntityManagerInterface $em;
    private Connection $connection;
    private VenueDetailQuery $query;

    protected function setUp(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        $locale = ListingQueryTest::enabledLocale();
        $this->query = new VenueDetailQuery($this->connection, new TranslationJoin($locale), $locale, $translator);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_image_urls_are_share_image_paths_up_to_the_limit(): void
    {
        $venue = DetailVenues::full($this->em);
        $detail = $this->query->detail($venue->number(), 'de');
        self::assertNotNull($detail);

        $urls = $detail->imageUrls(1);

        self::assertCount(1, $urls);
        self::assertSame($detail->photos[0]->shareImage()->url, $urls[0]);
        self::assertMatchesRegularExpression('#^/media/\d+/[0-9a-f-]+-1280\.jpg$#', $urls[0]);
    }

    public function test_a_venue_without_photos_has_no_image_urls(): void
    {
        $venue = DetailVenues::minimal();
        $detail = $this->query->detail($venue->number(), 'de');
        self::assertNotNull($detail);

        self::assertSame([], $detail->imageUrls(3));
    }

    public function test_resolves_a_current_slug(): void
    {
        $venue = DetailVenues::minimal();

        $match = $this->query->resolveSlug(DetailVenues::MINIMAL_SLUG, 'de');

        self::assertNotNull($match);
        self::assertSame($venue->id(), $match->venueId);
        self::assertSame($venue->number(), $match->number);
        self::assertSame(DetailVenues::MINIMAL_SLUG, $match->currentSlug);
        self::assertTrue($match->isCurrent);
        self::assertTrue($match->isPublished);
    }

    public function test_resolves_a_history_slug_to_the_current_one(): void
    {
        $venue = DetailVenues::minimal();
        VenueSlugHistoryFactory::createOne(['venue' => $venue, 'slug' => 'altes-gartenhaus']);

        $match = $this->query->resolveSlug('altes-gartenhaus', 'de');

        self::assertNotNull($match);
        self::assertSame($venue->id(), $match->venueId);
        self::assertSame(DetailVenues::MINIMAL_SLUG, $match->currentSlug);
        self::assertFalse($match->isCurrent);
        self::assertTrue($match->isPublished);
    }

    public function test_a_published_venue_without_a_town_has_a_detail(): void
    {
        $venue = DetailVenues::inCantonOnly();

        $detail = $this->query->detail($venue->number(), 'de');

        self::assertNotNull($detail);
        self::assertSame('Scheune Nur Kanton', $detail->name);
        self::assertNull($detail->street);
        self::assertNull($detail->zip);
        self::assertNull($detail->lat, 'NULL stays NULL, not 0.0');
        self::assertNull($detail->lng);
        self::assertFalse($detail->hasLocation());
        self::assertEquals(new VenuePlace(null, Canton::ZH, null), $detail->place);
    }

    public function test_distances_alone_are_a_location(): void
    {
        $venue = DetailVenues::withoutPlace();
        $venue->changeDistances('0.9', 'Bahnhof', null, null, null, null);
        $this->em->flush();

        $detail = $this->query->detail($venue->number(), 'de');

        self::assertNotNull($detail);
        self::assertTrue($detail->hasLocation());
    }

    public function test_an_unpublished_venue_is_matched_but_not_published(): void
    {
        $venue = DetailVenues::minimal();
        VenueSlugHistoryFactory::createOne(['venue' => $venue, 'slug' => 'altes-gartenhaus']);
        $venue->unpublish();
        $this->em->flush();

        foreach ([DetailVenues::MINIMAL_SLUG, 'altes-gartenhaus'] as $slug) {
            $match = $this->query->resolveSlug($slug, 'de');
            self::assertNotNull($match, $slug);
            self::assertFalse($match->isPublished, $slug);
        }
        self::assertNull($this->query->detail($venue->number(), 'de'));
    }

    public function test_a_venue_without_a_default_translation_counts_as_unpublished(): void
    {
        $venue = VenueFactory::createOne(['town' => DetailVenues::town('Bern')]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'locale' => 'fr', 'slug' => 'maison-sans-allemand']);
        VenueSlugHistoryFactory::createOne(['venue' => $venue, 'slug' => 'ohne-deutsch']);

        $byFrench = $this->query->resolveSlug('maison-sans-allemand', 'fr');
        self::assertNotNull($byFrench);
        self::assertFalse($byFrench->isPublished);

        $byHistory = $this->query->resolveSlug('ohne-deutsch', 'de');
        self::assertNotNull($byHistory);
        self::assertFalse($byHistory->isPublished);
        self::assertNull($this->query->detail($venue->number(), 'de'));
    }

    public function test_an_unknown_slug_gives_null(): void
    {
        DetailVenues::minimal();

        self::assertNull($this->query->resolveSlug('gibt-es-nicht', 'de'));
        self::assertNull($this->query->detail(999_999_999, 'de'));
        self::assertNull($this->query->detail(999_999_999, 'de', includeUnpublished: true));
    }

    public function test_includes_an_unpublished_venue_only_on_request(): void
    {
        $venue = DetailVenues::full($this->em);
        $venue->unpublish();
        $this->em->flush();

        self::assertNull($this->query->detail($venue->number(), 'de'));

        $detail = $this->query->detail($venue->number(), 'de', includeUnpublished: true);
        self::assertNotNull($detail);
        self::assertSame($venue->id(), $detail->id);
        self::assertSame(DetailVenues::FULL_SLUG, $detail->slug);
        self::assertNotSame([], $detail->rooms);
        self::assertNotSame([], $detail->photos);
    }

    public function test_an_unpublished_venue_still_needs_a_translation_in_the_locale_or_the_default(): void
    {
        $venue = VenueFactory::createOne(['town' => DetailVenues::town('Bern')]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'locale' => 'fr', 'slug' => 'maison-sans-allemand']);

        self::assertNull($this->query->detail($venue->number(), 'de', includeUnpublished: true));

        $detail = $this->query->detail($venue->number(), 'fr', includeUnpublished: true);
        self::assertNotNull($detail);
        self::assertSame('maison-sans-allemand', $detail->slug);
    }

    public function test_the_google_place_decides_whether_the_rating_shows(): void
    {
        $venue = DetailVenues::full($this->em);

        $none = $this->query->detail($venue->number(), 'de');
        self::assertNotNull($none);
        self::assertNull($none->googlePlaceId);
        self::assertFalse($none->googleRatingHidden);
        self::assertFalse($none->showsGoogleRating());

        $this->connection->executeStatement("UPDATE venues SET google_place_id = 'ChIJN1t_tDeuEmsRUsoyG83frY4' WHERE id = :id", ['id' => $venue->id()]);
        $set = $this->query->detail($venue->number(), 'de');
        self::assertNotNull($set);
        self::assertSame('ChIJN1t_tDeuEmsRUsoyG83frY4', $set->googlePlaceId);
        self::assertFalse($set->googleRatingHidden);
        self::assertTrue($set->showsGoogleRating());

        $this->connection->executeStatement('UPDATE venues SET google_rating_hidden = TRUE WHERE id = :id', ['id' => $venue->id()]);
        $hidden = $this->query->detail($venue->number(), 'de');
        self::assertNotNull($hidden);
        self::assertSame('ChIJN1t_tDeuEmsRUsoyG83frY4', $hidden->googlePlaceId);
        self::assertTrue($hidden->googleRatingHidden);
        self::assertFalse($hidden->showsGoogleRating());
    }

    public function test_maps_every_field_of_a_full_venue(): void
    {
        $venue = DetailVenues::full($this->em);

        $detail = $this->query->detail($venue->number(), 'de');

        self::assertNotNull($detail);
        self::assertSame($venue->id(), $detail->id);
        self::assertSame($venue->number(), $detail->number);
        self::assertSame('Schloss Seeblick', $detail->name);
        self::assertSame(Tier::Premium, $detail->tier);
        self::assertSame(DetailVenues::FULL_SLUG, $detail->slug);
        self::assertSame("Erster Absatz über das Schloss.\n\nZweiter Absatz mit dem Park.", $detail->descriptionText());
        self::assertSame('Schloss Seeblick: Hochzeit am See.', $detail->metaDescription);
        self::assertSame('Seestrasse 1', $detail->street);
        self::assertSame('Zürich', $detail->place->townName);
        self::assertSame(Canton::ZH, $detail->place->canton);
        self::assertNull($detail->place->region);
        self::assertSame(50, $detail->capacityMin);
        self::assertSame(300, $detail->capacityMax);
        self::assertEqualsWithDelta(DetailVenues::town('Zürich')->lat(), $detail->lat, 0.0001);
        self::assertFalse($detail->coordinatesFromTown);

        self::assertEquals(new Term(TermGroup::Building, 'castle'), $detail->mainBuilding);
        self::assertEquals(new Term(TermGroup::Setting, 'lake'), $detail->mainSetting);
        self::assertSame(
            [
                'building' => ['castle', 'villa'],
                'setting' => ['lake'],
                'suitable_for' => ['corporate_event', 'family_celebration', 'wedding'],
                'infrastructure' => ['air_conditioning', 'cloakroom'],
                'surroundings' => ['garden'],
            ],
            array_merge(...array_map(
                static fn (DetailFactGroup $group): array => [$group->group->value => array_map(static fn (Term $term): string => $term->code, $group->terms)],
                $detail->factGroups,
            )),
        );

        self::assertTrue($detail->hasFaq());
        self::assertSame(OwnCatering::Allowed, $detail->ownCatering);
        self::assertTrue($detail->daylight);
        self::assertSame('100 Parkplätze (kostenlos)', $detail->parkingText);

        self::assertCount(3, $detail->distances);
        self::assertSame([Access::Station, Access::Highway, Access::Airport], array_map(static fn ($d) => $d->access, $detail->distances));
        self::assertSame([0.9, 3.2, 12.0], array_map(static fn ($d) => $d->km, $detail->distances));
        self::assertSame(['Zürich Stadelhofen', null, 'Zürich'], array_map(static fn ($d) => $d->name, $detail->distances));

        self::assertTrue($detail->hasContact());
        self::assertTrue($detail->hasPhone);
        self::assertSame('info@seeblick.example', $detail->inquiryEmail);
        self::assertSame('https://seeblick.example', $detail->website);
        self::assertSame('Anna Muster', $detail->contactPerson);

        self::assertSame(['Preisliste', 'Menüvorschläge'], array_map(static fn ($d) => $d->title, $detail->documents));

        self::assertCount(2, $detail->rooms);
        [$hall, $cellar] = $detail->rooms;
        self::assertSame('Rittersaal', $hall->name);
        self::assertSame('Der grosse Saal mit Kronleuchtern.', $hall->description);
        self::assertSame([50, 300, 220], [$hall->capacityMin, $hall->capacityMax, $hall->areaM2]);
        self::assertSame(4.0, $hall->heightM);
        self::assertSame(FloorMaterial::Parquet, $hall->floorMaterial);
        self::assertEquals(
            [new DetailSeating(Seating::Banquet, 50, 180), new DetailSeating(Seating::Seminar, null, 120), new DetailSeating(Seating::Concert, 60, 250), new DetailSeating(Seating::Apero, 100, 300)],
            $hall->seatings,
        );
        self::assertTrue($hall->hasInfo());
        self::assertTrue($hall->hasFacts());
        self::assertNotNull($hall->photo);
        self::assertSame('Der Rittersaal', $hall->photo->alt);
        self::assertSame('Gewölbekeller', $cellar->name);
        self::assertFalse($cellar->hasInfo());
        self::assertFalse($cellar->hasFacts());
        self::assertNull($cellar->photo);

        self::assertSame(['Aussicht 1', 'Aussicht 2', 'Schloss Seeblick, Bild 3'], array_map(static fn (Picture $p): string => $p->alt, $detail->photos));
        self::assertSame($venue->number(), $detail->photos[0]->venueNumber);
        self::assertSame(1600, $detail->photos[0]->width);
    }

    public function test_town_centre_coordinates_are_flagged(): void
    {
        $venue = DetailVenues::full($this->em);
        $venue->relocate($venue->town(), $venue->zip(), $venue->street(), $venue->lat(), $venue->lng(), coordinatesFromTown: true);
        $this->em->flush();

        $detail = $this->query->detail($venue->number(), 'de');

        self::assertNotNull($detail);
        self::assertTrue($detail->coordinatesFromTown);
        self::assertNotNull($detail->lat, 'the coordinates stay; the page decides what to show');
    }

    public function test_a_minimal_venue_has_empty_sections(): void
    {
        $venue = DetailVenues::minimal();

        $detail = $this->query->detail($venue->number(), 'de');

        self::assertNotNull($detail);
        self::assertSame('Ein kleines Gartenhaus.', $detail->descriptionText());
        self::assertNull($detail->description);
        self::assertNull($detail->metaDescription);
        self::assertNull($detail->street);
        self::assertNull($detail->capacityMin);
        self::assertNull($detail->mainBuilding);
        self::assertNull($detail->mainSetting);
        self::assertSame([], $detail->factGroups);
        self::assertFalse($detail->hasFacts());
        self::assertFalse($detail->hasFaq());
        self::assertSame([], $detail->distances);
        self::assertFalse($detail->event->hasAny());
        self::assertNull($detail->instagram);
        self::assertFalse($detail->hasPhone);
        self::assertFalse($detail->hasContact());
        self::assertSame([], $detail->documents);
        self::assertSame([], $detail->rooms);
        self::assertSame([], $detail->photos);
    }

    public function test_maps_every_event_fact_of_a_full_venue(): void
    {
        $venue = DetailVenues::full($this->em);

        $detail = $this->query->detail($venue->number(), 'de');

        self::assertNotNull($detail);
        $event = $detail->event;
        self::assertTrue($event->hasAny());
        self::assertSame([PriceLevel::Medium, 1200, 1500], [$event->priceLevel, $event->capacitySeated, $event->capacityStanding]);
        self::assertSame(
            ['Romantisch am Wasser', 'Mit Tanzfläche 900 Sitzplätze.', 'Mai bis Oktober, täglich ab 10 Uhr'],
            [$event->ambience, $event->capacityNote, $event->openingHours],
        );
        $codes = static fn (array $terms): array => array_map(static fn (Term $term): string => $term->code, $terms);
        self::assertSame(['dance_floor'], $codes($event->amenities));
        self::assertSame(['vegetarian', 'gluten_free'], $codes($event->catering), 'position order, not insertion order');
        self::assertSame(['de', 'en'], $codes($event->languages));
        self::assertSame('https://www.instagram.com/schloss.seeblick', $detail->instagram);

        self::assertSame(
            [TermGroup::Building, TermGroup::Setting, TermGroup::SuitableFor, TermGroup::Infrastructure, TermGroup::Surroundings],
            array_map(static fn (DetailFactGroup $group): TermGroup => $group->group, $detail->factGroups),
            'the event groups stay out of the fact lists',
        );
    }

    public function test_a_venue_with_only_instagram_has_a_contact_section(): void
    {
        $venue = DetailVenues::minimal();
        $venue->changeInstagram('https://www.instagram.com/gartenhaus');
        $this->em->flush();

        $detail = $this->query->detail($venue->number(), 'de');

        self::assertNotNull($detail);
        self::assertTrue($detail->hasContact());
        self::assertFalse($detail->event->hasAny(), 'Instagram belongs to Kontakt');
    }

    public function test_a_price_level_alone_is_a_known_fact(): void
    {
        $venue = DetailVenues::minimal();
        $venue->changePriceLevel(PriceLevel::Moderate);
        $this->em->flush();

        $detail = $this->query->detail($venue->number(), 'de');

        self::assertNotNull($detail);
        self::assertTrue($detail->event->hasAny());
        self::assertSame([PriceLevel::Moderate, null, null, null, null, null, [], [], []], array_values(get_object_vars($detail->event)));
    }

    public function test_a_standing_capacity_alone_is_a_known_fact(): void
    {
        $venue = DetailVenues::minimal();
        $venue->changeSeatingCapacity(null, 60);
        $this->em->flush();

        $detail = $this->query->detail($venue->number(), 'de');

        self::assertNotNull($detail);
        self::assertTrue($detail->event->hasAny());
        self::assertSame([null, 60], [$detail->event->capacitySeated, $detail->event->capacityStanding]);
    }

    /**
     * @return iterable<string, array{TermGroup, string}>
     */
    public static function eventTerms(): iterable
    {
        yield 'an amenity' => [TermGroup::Amenity, 'parking'];
        yield 'a catering term' => [TermGroup::Catering, 'buffet'];
        yield 'a language' => [TermGroup::Language, 'gsw'];
    }

    #[DataProvider('eventTerms')]
    public function test_an_event_term_alone_is_a_known_fact_and_no_fact_list_row(TermGroup $group, string $code): void
    {
        $venue = DetailVenues::minimal();
        VenueTermFactory::new()->term($group, $code)->create(['venue' => $venue]);
        $this->em->flush();

        $detail = $this->query->detail($venue->number(), 'de');

        self::assertNotNull($detail);
        self::assertTrue($detail->event->hasAny());
        self::assertSame([], $detail->factGroups);
    }

    public function test_technology_and_furniture_terms_are_fact_lists_and_no_event_facts(): void
    {
        $venue = DetailVenues::minimal();
        VenueTermFactory::new()->term(TermGroup::Technology, 'wifi')->create(['venue' => $venue]);
        VenueTermFactory::new()->term(TermGroup::Technology, 'video_conferencing')->create(['venue' => $venue]);
        VenueTermFactory::new()->term(TermGroup::Furniture, 'standing_tables')->create(['venue' => $venue]);
        $this->em->flush();

        $detail = $this->query->detail($venue->number(), 'de');

        self::assertNotNull($detail);
        self::assertFalse($detail->event->hasAny());
        self::assertSame(
            ['furniture' => ['standing_tables'], 'technology' => ['wifi', 'video_conferencing']],
            array_merge(...array_map(
                static fn (DetailFactGroup $group): array => [$group->group->value => array_map(static fn (Term $term): string => $term->code, $group->terms)],
                $detail->factGroups,
            )),
            'DP-7 order: furniture before technology',
        );
    }

    public function test_phone_is_read_separately(): void
    {
        $full = DetailVenues::full($this->em);
        $minimal = DetailVenues::minimal();

        self::assertSame(DetailVenues::FULL_PHONE, $this->query->phone($full->id()));
        self::assertNull($this->query->phone($minimal->id()));
        self::assertNull($this->query->phone('abc'));
    }

    public function test_query_count(): void
    {
        $venue = DetailVenues::full($this->em);
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);

        $holder->reset();
        $this->query->resolveSlug(DetailVenues::FULL_SLUG, 'de');
        self::assertCount(1, $holder->getData()['default'] ?? []);

        $holder->reset();
        self::assertNotNull($this->query->detail($venue->number(), 'de'));
        self::assertCount(3, $holder->getData()['default'] ?? []);

        $holder->reset();
        self::assertNotNull($this->query->detail($venue->number(), 'de', includeUnpublished: true));
        self::assertCount(3, $holder->getData()['default'] ?? []);
    }
}
