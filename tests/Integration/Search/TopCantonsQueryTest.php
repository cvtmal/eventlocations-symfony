<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Factory\VenueFactory;
use App\Factory\VenueTranslationFactory;
use App\Geo\Entity\Town;
use App\Geo\ValueObject\Canton;
use App\Search\ReadModel\CantonCount;
use App\Search\ReadModel\TopCantonsQuery;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

use function Zenstruck\Foundry\Persistence\repository;

/**
 * The published venues per canton behind "Andere suchten auch nach". Each test runs in a
 * rolled-back transaction.
 */
final class TopCantonsQueryTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;

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

    public function test_cantons_are_ordered_by_published_venues(): void
    {
        $this->venues('Zürich', 2);
        $this->venues('Stäfa', 1);
        $this->venues('Bern', 2);
        $this->venues('Genève', 1);
        $this->venues('Genève', 1, VenueStatus::Unpublished);
        VenueFactory::createOne(['town' => $this->town('Genève')]); // no translation: not published

        self::assertSame([['zh', 3], ['be', 2], ['ge', 1]], $this->pairs($this->query()->top()));
    }

    public function test_the_limit_and_equal_counts_ordered_by_code(): void
    {
        $this->venues('Genève', 1);
        $this->venues('Bern', 1);
        $this->venues('Zürich', 1);

        self::assertSame([['be', 1], ['ge', 1]], $this->pairs($this->query()->top(2)));
        self::assertSame(Canton::ZH, $this->query()->top()[2]->canton);
    }

    public function test_venues_without_a_canton_are_left_out(): void
    {
        $this->venues('Zürich', 2);
        DetailVenues::withoutPlace();
        DetailVenues::inRegionOnly($this->em, 'Schweizweit');
        DetailVenues::inCantonOnly('scheune-bern', 'Scheune Bern', 'be');

        self::assertSame([['zh', 2], ['be', 1]], $this->pairs($this->query()->top()));
    }

    public function test_no_published_venue_gives_no_canton(): void
    {
        self::assertSame([], $this->query()->top());
    }

    private function query(): TopCantonsQuery
    {
        return new TopCantonsQuery($this->connection, ListingQueryTest::enabledLocale());
    }

    private function venues(string $town, int $count, VenueStatus $status = VenueStatus::Published): void
    {
        for ($i = 0; $i < $count; ++$i) {
            VenueTranslationFactory::createOne(['venue' => VenueFactory::createOne(['town' => $this->town($town), 'status' => $status])]);
        }
    }

    /**
     * @param list<CantonCount> $counts
     *
     * @return list<array{string, int}>
     */
    private function pairs(array $counts): array
    {
        return array_map(static fn (CantonCount $count): array => [$count->canton->value, $count->count], $counts);
    }

    private function town(string $name): Town
    {
        $town = repository(Town::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Town::class, $town);

        return $town;
    }
}
