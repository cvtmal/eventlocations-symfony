<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Factory\VenueFactory;
use App\Factory\VenueTranslationFactory;
use App\Search\ReadModel\NearbyVenue;
use App\Search\ReadModel\NearbyVenuesQuery;
use App\Search\ValueObject\NearbyPoint;
use App\Shared\Translation\TranslationJoin;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * #52's read model against app_test: distance order, the 30 km edge, the caps and the published
 * predicate. Each test runs in a rolled-back transaction.
 */
final class NearbyVenuesQueryTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;

    private const float LAT = 47.0;
    private const float LNG = 8.2;

    private EntityManagerInterface $em;
    private Connection $connection;

    protected function setUp(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_venues_come_nearest_first_and_the_number_breaks_a_tie(): void
    {
        $far = $this->venue('Weit', self::LAT + 0.1);
        $second = $this->venue('Zweite am Punkt', self::LAT + 0.01);
        $first = $this->venue('Erste am Punkt', self::LAT + 0.01);
        $centre = $this->venue('Mitte', self::LAT);

        $venues = $this->query()->within($this->centre(), 'de');

        $expected = [$centre->number(), ...self::sorted([$first->number(), $second->number()]), $far->number()];
        self::assertSame($expected, array_map(static fn (NearbyVenue $v): int => $v->number, $venues));
        self::assertEqualsWithDelta(0.0, $venues[0]->distanceKm, 0.01);
        // earthdistance's sphere: 6378.168 km, so 0.1° of latitude is 11.13 km.
        self::assertEqualsWithDelta(11.132, $venues[3]->distanceKm, 0.01);
        self::assertSame('Mitte', $venues[0]->name);
        self::assertSame(self::LAT, $venues[0]->lat);
    }

    public function test_the_radius_ends_at_30_km(): void
    {
        $this->venue('Innen', self::LAT + 0.2680);
        $this->venue('Aussen', self::LAT + 0.2710);

        $venues = $this->query()->within($this->centre(), 'de');

        self::assertSame(['Innen'], array_map(static fn (NearbyVenue $v): string => $v->name, $venues));
        self::assertLessThan(30.0, $venues[0]->distanceKm);
    }

    public function test_unpublished_untranslated_and_unplaced_venues_are_left_out(): void
    {
        $this->venue('Publiziert', self::LAT);
        $this->venue('Entwurf', self::LAT, attributes: ['status' => VenueStatus::Unpublished]);
        $untranslated = VenueFactory::createOne(['name' => 'Ohne Deutsch', 'lat' => self::LAT, 'lng' => self::LNG]);
        VenueTranslationFactory::createOne(['venue' => $untranslated, 'locale' => 'fr']);
        $unplaced = VenueFactory::new()->withoutLocation()->create(['name' => 'Ohne Koordinaten']);
        VenueTranslationFactory::createOne(['venue' => $unplaced]);

        $within = $this->query()->within($this->centre(), 'de');
        $all = $this->query()->all('de');

        self::assertSame(['Publiziert'], array_map(static fn (NearbyVenue $v): string => $v->name, $within));
        self::assertSame(['Publiziert'], array_map(static fn (NearbyVenue $v): string => $v->name, $all));
    }

    public function test_within_is_capped(): void
    {
        for ($i = 0; $i <= NearbyVenuesQuery::LIMIT; ++$i) {
            $this->venue('Venue '.$i, self::LAT + $i * 0.0001);
        }

        self::assertCount(NearbyVenuesQuery::LIMIT, $this->query()->within($this->centre(), 'de'));
    }

    public function test_all_lists_every_published_venue_in_number_order_without_a_distance(): void
    {
        $a = $this->venue('Bern', 46.95, 7.45);
        $b = $this->venue('Genf', 46.2, 6.15);
        $c = $this->venue('Chur', 46.85, 9.53);

        $venues = $this->query()->all('de');

        self::assertSame(self::sorted([$a->number(), $b->number(), $c->number()]), array_map(static fn (NearbyVenue $v): int => $v->number, $venues));
        foreach ($venues as $venue) {
            self::assertNull($venue->distanceKm);
        }
    }

    private function centre(): NearbyPoint
    {
        $point = NearbyPoint::fromQuery((string) self::LAT, (string) self::LNG);
        self::assertNotNull($point);

        return $point;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function venue(string $name, float $lat, float $lng = self::LNG, array $attributes = []): Venue
    {
        $venue = VenueFactory::createOne(['name' => $name, 'lat' => $lat, 'lng' => $lng] + $attributes);
        VenueTranslationFactory::createOne(['venue' => $venue]);

        return $venue;
    }

    /**
     * @param list<int> $numbers
     *
     * @return list<int>
     */
    private static function sorted(array $numbers): array
    {
        sort($numbers);

        return $numbers;
    }

    private function query(): NearbyVenuesQuery
    {
        $locale = ListingQueryTest::enabledLocale();

        return new NearbyVenuesQuery($this->connection, new TranslationJoin($locale), $locale);
    }
}
