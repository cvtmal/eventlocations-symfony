<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\ReadModel\GooglePlaceLookupQuery;
use App\Venues\ReadModel\GooglePlaceLookupVenue;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

final class GooglePlaceLookupQueryTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;

    private EntityManagerInterface $em;
    private Connection $connection;
    private GooglePlaceLookupQuery $query;

    protected function setUp(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $this->query = new GooglePlaceLookupQuery($this->connection);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_it_lists_the_venues_without_a_place_id_by_number(): void
    {
        $pinned = DetailVenues::inCantonWithPin('47.3', '8.5');
        $region = DetailVenues::inRegionOnly($this->em, 'Bodensee');
        $withId = DetailVenues::minimal();
        $this->connection->executeStatement("UPDATE venues SET google_place_id = 'ChIJN1t_tDeuEmsRUsoyG83frY4' WHERE id = ?", [$withId->id()]);
        $this->connection->executeStatement("UPDATE venues SET status = 'unpublished' WHERE id = ?", [$region->id()]);

        $venues = $this->mine([$pinned->number(), $region->number(), $withId->number()]);

        self::assertEquals([
            new GooglePlaceLookupVenue($pinned->number(), 'Scheune Mit Pin', 'zh', '', 47.3, 8.5, true),
            new GooglePlaceLookupVenue($region->number(), 'Remise Nur Region', '', 'Bodensee', null, null, false),
        ], $venues);
    }

    public function test_town_centre_coordinates_are_no_pin(): void
    {
        $venue = DetailVenues::minimal();
        $this->connection->executeStatement('UPDATE venues SET lat = 46.948, lng = 7.4474, coordinates_from_town = TRUE WHERE id = ?', [$venue->id()]);

        [$found] = $this->mine([$venue->number()]);

        self::assertSame(['be', null, null], [$found->cantonCode, $found->lat, $found->lng]);
    }

    public function test_the_limit_counts_from_the_lowest_number(): void
    {
        $first = DetailVenues::inCantonOnly();
        DetailVenues::withoutPlace();
        $all = $this->query->withoutPlace();

        $limited = $this->query->withoutPlace(1);

        self::assertGreaterThanOrEqual(2, \count($all));
        self::assertCount(1, $limited);
        self::assertSame($all[0]->number, $limited[0]->number);
        self::assertLessThanOrEqual($first->number(), $limited[0]->number);
    }

    /**
     * The rows of the given venues only: the test database may hold venues of other tests' fixtures.
     *
     * @param list<int> $numbers
     *
     * @return list<GooglePlaceLookupVenue>
     */
    private function mine(array $numbers): array
    {
        return array_values(array_filter($this->query->withoutPlace(), static fn (GooglePlaceLookupVenue $venue): bool => \in_array($venue->number, $numbers, true)));
    }
}
