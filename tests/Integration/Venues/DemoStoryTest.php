<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Geo\Repository\TownRepository;
use App\Story\DemoStory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Venues\Repository\TaxonomyTermRepository;
use App\Venues\Repository\VenueRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * DemoStory against the real towns: under 10 s, the promised spread, idempotent. Each test
 * runs in a rolled-back transaction, so app_test keeps no venues.
 */
final class DemoStoryTest extends KernelTestCase
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

    public function test_loads_sixty_venues_with_the_promised_spread_in_under_ten_seconds(): void
    {
        self::assertSame(0, $this->rows('SELECT count(*) FROM venues'));

        $start = hrtime(true);
        $this->story()->build();
        $seconds = (hrtime(true) - $start) / 1e9;

        self::assertLessThan(10.0, $seconds);
        self::assertSame(DemoStory::VENUES, $this->rows('SELECT count(*) FROM venues'));
        self::assertSame(26, $this->rows('SELECT count(DISTINCT canton_code) FROM venues'));
        self::assertSame(
            ['beliebte_location' => 3, 'empfehlung' => 6, 'top_premium' => 9, 'premium' => 12, 'basic' => 30],
            array_map('intval', $this->connection->fetchAllKeyValue('SELECT tier, count(*) FROM venues GROUP BY tier ORDER BY min(tier_rank)')),
        );
        self::assertSame(DemoStory::VENUES, $this->rows("SELECT count(DISTINCT slug) FROM venue_translations WHERE locale = 'de'"));
        self::assertSame(DemoStory::VENUES, $this->rows('SELECT count(DISTINCT name) FROM venues'));
        self::assertGreaterThan(0, $this->rows('SELECT count(*) FROM venues WHERE rating_score IS NULL'));
        self::assertGreaterThan(0, $this->rows('SELECT count(*) FROM venues WHERE rating_score < 7.7'));
        self::assertSame(0, $this->rows('SELECT count(*) FROM venue_photos'));
        self::assertSame(0, $this->rows("SELECT count(*) FROM venue_translations WHERE summary LIKE '%ß%' OR description LIKE '%ß%'"));

        $perVenue = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT v.room_count,
                (SELECT count(*) FROM rooms r WHERE r.venue_id = v.id) AS rooms,
                (SELECT count(*) FROM venue_reviews r WHERE r.venue_id = v.id) AS reviews,
                (SELECT count(*) FROM venue_documents d WHERE d.venue_id = v.id) AS documents,
                (SELECT count(*) FROM venue_terms t WHERE t.venue_id = v.id AND t.term_group = 'building' AND t.is_main) AS main_buildings,
                (SELECT count(*) FROM venue_terms t WHERE t.venue_id = v.id AND t.term_group = 'setting' AND t.is_main) AS main_settings,
                (SELECT count(*) FROM venue_terms t WHERE t.venue_id = v.id AND t.term_group = 'suitable_for' AND t.term_code = 'wedding') AS wedding,
                (SELECT max(r.capacity_max) FROM rooms r WHERE r.venue_id = v.id) AS largest_room_capacity,
                v.capacity_max
            FROM venues v
            SQL);
        foreach ($perVenue as $venue) {
            self::assertSame((int) $venue['rooms'], (int) $venue['room_count']);
            self::assertGreaterThanOrEqual(1, (int) $venue['rooms']);
            self::assertLessThanOrEqual(5, (int) $venue['rooms']);
            self::assertLessThanOrEqual(3, (int) $venue['reviews']);
            self::assertLessThanOrEqual(2, (int) $venue['documents']);
            self::assertSame(1, (int) $venue['main_buildings']);
            self::assertSame(1, (int) $venue['main_settings']);
            self::assertSame(1, (int) $venue['wedding']);
            self::assertSame((int) $venue['capacity_max'], (int) $venue['largest_room_capacity']);
        }

        $this->story()->build();
        self::assertSame(DemoStory::VENUES, $this->rows('SELECT count(*) FROM venues'));
    }

    public function test_refuses_to_load_without_towns(): void
    {
        $this->connection->executeStatement('DELETE FROM towns');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('app:geo:import-towns');

        $this->story()->build();
    }

    private function story(): DemoStory
    {
        $container = self::getContainer();
        $venues = $container->get(VenueRepository::class);
        $terms = $container->get(TaxonomyTermRepository::class);
        $towns = $container->get(TownRepository::class);
        self::assertInstanceOf(VenueRepository::class, $venues);
        self::assertInstanceOf(TaxonomyTermRepository::class, $terms);
        self::assertInstanceOf(TownRepository::class, $towns);

        return new DemoStory($venues, $terms, $towns);
    }

    private function rows(string $sql): int
    {
        return (int) $this->connection->fetchOne($sql);
    }
}
