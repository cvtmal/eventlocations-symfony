<?php

declare(strict_types=1);

namespace App\Tests\Integration\Geo;

use App\Geo\ReadModel\TownDistance;
use App\Geo\ReadModel\TownLocator;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TownLocatorTest extends KernelTestCase
{
    use ImportsTownDataset;

    private TownLocator $locator;
    private string $stafaId;

    protected function setUp(): void
    {
        // Not wired anywhere yet (#6 adds the radius filter), so the container has removed the service.
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->locator = new TownLocator($connection);

        $id = $connection->fetchOne("SELECT id FROM towns WHERE canton_code = 'zh' AND name = 'Stäfa'");
        self::assertIsString($id);
        $this->stafaId = $id;
    }

    public function test_towns_within_five_km_of_stafa(): void
    {
        $towns = $this->locator->withinKm($this->stafaId, 5);
        $names = array_map(static fn (TownDistance $t): string => $t->name, $towns);
        $distances = array_map(static fn (TownDistance $t): float => $t->distanceKm, $towns);

        self::assertSame('Stäfa', $towns[0]->name);
        self::assertSame($this->stafaId, $towns[0]->townId);
        self::assertEqualsWithDelta(0.0, $towns[0]->distanceKm, 0.001);
        self::assertContains('Männedorf', $names);
        self::assertContains('Uerikon', $names);
        self::assertNotContains('Zürich', $names);

        $sorted = $distances;
        sort($sorted);
        self::assertSame($sorted, $distances);
        foreach ($distances as $distance) {
            self::assertLessThanOrEqual(5.0, $distance);
        }
    }

    public function test_a_larger_radius_finds_more_towns(): void
    {
        self::assertGreaterThan(
            \count($this->locator->withinKm($this->stafaId, 5)),
            \count($this->locator->withinKm($this->stafaId, 10)),
        );
    }

    public function test_radius_must_be_positive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->locator->withinKm($this->stafaId, 0);
    }

    public function test_radius_is_capped(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->locator->withinKm($this->stafaId, 201);
    }

    public function test_unknown_or_invalid_town_gives_nothing(): void
    {
        self::assertSame([], $this->locator->withinKm('0192f1c4-0000-7000-8000-000000000000', 5));
        self::assertSame([], $this->locator->withinKm('not-a-uuid', 5));
    }

    public function test_nearest_gives_the_closest_town_to_a_point(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $stafa = $connection->fetchAssociative('SELECT lat, lng FROM towns WHERE id = :id', ['id' => $this->stafaId]);
        self::assertIsArray($stafa);
        self::assertIsNumeric($stafa['lat']);
        self::assertIsNumeric($stafa['lng']);

        // About 220 m north of Stäfa's centre.
        $town = $this->locator->nearest((float) $stafa['lat'] + 0.002, (float) $stafa['lng']);

        self::assertInstanceOf(TownDistance::class, $town);
        self::assertSame('Stäfa', $town->name);
        self::assertLessThan(1.0, $town->distanceKm);
    }

    public function test_nearest_is_null_without_a_town_within_20_km(): void
    {
        // In the Aosta valley: inside near-me's box (CH + 30 km), but far from any Swiss town.
        self::assertNull($this->locator->nearest(45.60, 7.50));
    }
}
