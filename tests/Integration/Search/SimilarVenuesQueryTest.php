<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenueTranslationFactory;
use App\Geo\Entity\Town;
use App\Search\ReadModel\SimilarVenue;
use App\Search\ReadModel\SimilarVenuesQuery;
use App\Shared\Translation\TranslationJoin;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\EntitlementResolver;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\KnownTiers;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

use function Zenstruck\Foundry\Persistence\repository;

final class SimilarVenuesQueryTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;

    private EntityManagerInterface $em;
    private Connection $connection;
    private SimilarVenuesQuery $query;
    private Town $zurich;

    protected function setUp(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $locale = ListingQueryTest::enabledLocale();
        $this->query = new SimilarVenuesQuery($this->connection, new TranslationJoin($locale), $locale, new EntitlementResolver($this->connection), new MockClock('2026-11-07 10:00:00'));
        $this->zurich = $this->town('Zürich');
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_returns_twelve_and_never_the_venue_itself(): void
    {
        $me = $this->venue('Ich', $this->zurich);
        for ($i = 0; $i < 14; ++$i) {
            $this->venue('Andere '.$i, $this->zurich);
        }

        $similar = $this->query->forVenue($me->id(), 'de');

        self::assertCount(12, $similar);
        self::assertNotContains($me->id(), array_map(static fn (SimilarVenue $v): string => $v->id, $similar));
    }

    public function test_same_canton_first_even_when_farther(): void
    {
        $me = $this->venue('Ich', $this->zurich);
        $winterthur = $this->town('Winterthur');
        // In canton Aargau, but placed right next to the venue.
        $this->venue('Aargau nebenan', $this->town('Aarau'), lat: $this->zurich->lat() + 0.001, lng: $this->zurich->lng());
        $this->venue('Winterthur', $winterthur, lat: $winterthur->lat(), lng: $winterthur->lng());

        self::assertSame(['Winterthur', 'Aargau nebenan'], $this->names($me));
    }

    public function test_overlapping_capacity_before_the_rest_then_nearer_first(): void
    {
        $me = $this->venue('Ich', $this->zurich, min: 50, max: 150);
        $winterthur = $this->town('Winterthur');
        $this->venue('Zu gross, nah', $this->zurich, min: 300, max: 500, lat: $this->zurich->lat() + 0.001, lng: $this->zurich->lng());
        $this->venue('Passt, weit', $winterthur, min: null, max: 100, lat: $winterthur->lat(), lng: $winterthur->lng());
        $this->venue('Passt, nah', $this->zurich, min: 100, max: 200, lat: $this->zurich->lat() + 0.01, lng: $this->zurich->lng());

        self::assertSame(['Passt, nah', 'Passt, weit', 'Zu gross, nah'], $this->names($me));
    }

    public function test_unpublished_venues_are_excluded(): void
    {
        $me = $this->venue('Ich', $this->zurich);
        $this->venue('Publiziert', $this->zurich);
        $hidden = $this->venue('Entwurf', $this->zurich);
        $hidden->unpublish();
        $this->em->flush();

        self::assertSame(['Publiziert'], $this->names($me));
    }

    public function test_a_candidate_without_a_town_appears(): void
    {
        $me = $this->venue('Ich', $this->zurich);
        DetailVenues::inCantonOnly();

        self::assertSame(['Scheune Nur Kanton'], $this->names($me));
    }

    public function test_a_candidate_without_a_canton_sorts_after_the_same_canton(): void
    {
        $me = $this->venue('Ich', $this->zurich);
        DetailVenues::withoutPlace();
        $this->venue('Zürich nebenan', $this->zurich);

        self::assertSame(['Zürich nebenan', 'Wiesenhof Ohne Ort'], $this->names($me));
    }

    public function test_a_venue_without_a_place_still_gets_similar_venues(): void
    {
        $me = DetailVenues::withoutPlace();
        $this->venue('Mit Ort', $this->zurich);
        DetailVenues::inRegionOnly($this->em, 'Schweizweit');

        $names = $this->names($me);
        sort($names);

        self::assertSame(['Mit Ort', 'Remise Nur Region'], $names);
    }

    public function test_an_invalid_or_unknown_id_gives_nothing(): void
    {
        $this->venue('Andere', $this->zurich);

        self::assertSame([], $this->query->forVenue('abc', 'de'));
        self::assertSame([], $this->query->forVenue(Uuid::v7()->toRfc4122(), 'de'));
    }

    public function test_carries_the_first_venue_photo(): void
    {
        $me = $this->venue('Ich', $this->zurich);
        $withPhotos = $this->venue('Mit Fotos', $this->zurich, lat: $this->zurich->lat() + 0.001, lng: $this->zurich->lng());
        VenuePhotoFactory::createOne(['venue' => $withPhotos, 'position' => 2]);
        $first = VenuePhotoFactory::createOne(['venue' => $withPhotos, 'position' => 1]);
        $this->venue('Ohne Fotos', $this->zurich, lat: $this->zurich->lat() + 0.01, lng: $this->zurich->lng());

        $similar = $this->query->forVenue($me->id(), 'de');

        self::assertSame('Mit Fotos', $similar[0]->name);
        self::assertNotNull($similar[0]->photo);
        self::assertSame($first->id(), $similar[0]->photo->photoId);
        self::assertSame($withPhotos->number(), $similar[0]->photo->venueNumber);
        self::assertSame($withPhotos->number(), $similar[0]->number);
        self::assertSame('Zürich', $similar[0]->place->townName);
        self::assertNull($similar[1]->photo);
    }

    public function test_a_pro_card_is_promoted_and_a_free_card_is_not(): void
    {
        $me = $this->venue('Ich', $this->zurich);
        $this->venue('Bezahlt', $this->zurich, lat: $this->zurich->lat() + 0.001, lng: $this->zurich->lng(), tier: KnownTiers::PRO);
        $this->venue('Gratis', $this->zurich, lat: $this->zurich->lat() + 0.01, lng: $this->zurich->lng());

        $similar = $this->query->forVenue($me->id(), 'de');

        self::assertSame([['Bezahlt', true], ['Gratis', false]], array_map(static fn (SimilarVenue $venue): array => [$venue->name, $venue->promoted], $similar));
    }

    public function test_a_plan_without_photos_shows_no_photo(): void
    {
        $me = $this->venue('Ich', $this->zurich);
        $other = $this->venue('Mit Fotos', $this->zurich, lat: $this->zurich->lat() + 0.001, lng: $this->zurich->lng());
        VenuePhotoFactory::createOne(['venue' => $other, 'position' => 1]);
        self::assertNotNull($this->query->forVenue($me->id(), 'de')[0]->photo);

        $this->connection->executeStatement("UPDATE tiers SET photos_max = 0 WHERE code = 'free'");

        self::assertNull($this->query->forVenue($me->id(), 'de')[0]->photo);
    }

    /**
     * @return list<string>
     */
    private function names(Venue $me): array
    {
        return array_map(static fn (SimilarVenue $v): string => $v->name, $this->query->forVenue($me->id(), 'de'));
    }

    private function venue(string $name, Town $town, ?int $min = null, int $max = 120, ?float $lat = null, ?float $lng = null, string $tier = KnownTiers::FREE): Venue
    {
        $venue = VenueFactory::createOne(array_filter([
            'name' => $name,
            'tier' => $tier,
            'town' => $town,
            'capacityMin' => $min,
            'capacityMax' => $max,
            'status' => VenueStatus::Published,
            'lat' => $lat,
            'lng' => $lng,
        ], static fn (mixed $value): bool => null !== $value));
        VenueTranslationFactory::createOne(['venue' => $venue]);

        return $venue;
    }

    private function town(string $name): Town
    {
        $town = repository(Town::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Town::class, $town);

        return $town;
    }
}
