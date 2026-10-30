<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Factory\VenueFactory;
use App\Factory\VenueTranslationFactory;
use App\Search\ReadModel\FilterDataQuery;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

final class FilterDataQueryTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;

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

    public function test_no_venues_means_no_data(): void
    {
        self::assertFalse($this->query->has('place'));
    }

    public function test_published_venues_without_a_town_are_no_data(): void
    {
        DetailVenues::withoutPlace();
        DetailVenues::inCantonOnly();

        self::assertFalse($this->query->has('place'));
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
    }

    public function test_a_second_call_runs_no_query_and_reset_asks_again(): void
    {
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);
        self::assertFalse($this->query->has('place'));
        DetailVenues::minimal();

        $holder->reset();
        self::assertFalse($this->query->has('place'));
        self::assertCount(0, $holder->getData()['default'] ?? []);

        $this->query->reset();
        self::assertTrue($this->query->has('place'));
    }

    public function test_an_unknown_filter_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->query->has('palce');
    }
}
