<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Geo\Repository\TownRepository;
use App\Media\MediaPaths;
use App\Media\ValueObject\ImageFormat;
use App\Media\ValueObject\ImageVariants;
use App\Story\DemoMedia;
use App\Story\DemoStory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Media\MediaFiles;
use App\Venues\Repository\TaxonomyTermRepository;
use App\Venues\Repository\VenueRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * DemoStory against the real towns: under 60 s (with DemoMedia's variant cache warm), the
 * promised spread, photos and PDFs on disk, idempotent. Each test runs in a rolled-back
 * transaction, so app_test keeps no venues; the test public dir is removed afterwards.
 */
final class DemoStoryTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;

    private EntityManagerInterface $em;
    private Connection $connection;
    private MediaPaths $paths;

    protected function setUp(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $paths = self::getContainer()->get(MediaPaths::class);
        self::assertInstanceOf(MediaPaths::class, $paths);
        $this->paths = $paths;
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        MediaFiles::removeDirectory($this->paths->publicDirectory());
        parent::tearDown();
    }

    public function test_loads_sixty_venues_with_the_promised_spread_in_under_sixty_seconds(): void
    {
        self::assertSame(0, $this->rows('SELECT count(*) FROM venues'));
        // The cold run renders the 30 originals' variants once (measured in make seed); the budget is for a warm cache.
        $this->media()->warm();

        $start = hrtime(true);
        $this->story()->build();
        $seconds = (hrtime(true) - $start) / 1e9;

        self::assertLessThan(60.0, $seconds);
        self::assertSame(DemoStory::VENUES, $this->rows('SELECT count(*) FROM venues'));
        self::assertSame(27, $this->rows('SELECT count(DISTINCT canton_code) FROM venues'));
        // #46: one Liechtenstein venue, canton only like the owner's export.
        self::assertSame(1, $this->rows("SELECT count(*) FROM venues WHERE canton_code = 'li'"));
        self::assertSame(1, $this->rows("SELECT count(*) FROM venues WHERE canton_code = 'li' AND town_id IS NULL"));
        self::assertSame(1, $this->rows("SELECT count(*) FROM venues WHERE canton_code = 'li' AND phone LIKE '+423%'"));
        self::assertSame(1, $this->rows("SELECT count(*) FROM venues WHERE phone LIKE '+423%'"));
        self::assertSame(4, $this->rows('SELECT count(*) FROM venues WHERE town_id IS NULL'));
        self::assertSame(2, $this->rows('SELECT count(*) FROM venues WHERE town_id IS NULL AND canton_code IS NOT NULL'));
        self::assertSame(0, $this->rows('SELECT count(*) FROM venues WHERE town_id IS NULL AND (zip IS NOT NULL OR street IS NOT NULL OR lat IS NOT NULL)'));
        self::assertSame(1, $this->rows('SELECT count(*) FROM venue_translations WHERE region IS NOT NULL'));
        self::assertSame(
            ['beliebte_location' => 3, 'empfehlung' => 6, 'top_premium' => 9, 'premium' => 12, 'basic' => 30],
            array_map('intval', $this->connection->fetchAllKeyValue('SELECT tier, count(*) FROM venues GROUP BY tier ORDER BY min(tier_rank)')),
        );
        self::assertSame(DemoStory::VENUES, $this->rows("SELECT count(DISTINCT slug) FROM venue_translations WHERE locale = 'de'"));
        self::assertSame(DemoStory::VENUES, $this->rows('SELECT count(DISTINCT name) FROM venues'));
        self::assertGreaterThan(0, $this->rows('SELECT count(*) FROM venues WHERE rating_score IS NULL'));
        self::assertGreaterThan(0, $this->rows('SELECT count(*) FROM venues WHERE rating_score < 7.7'));
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

        $this->assertWeddingFacts();
        $this->assertPhotos();
        $this->assertDocuments();

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

    private function assertWeddingFacts(): void
    {
        self::assertSame(30, $this->rows('SELECT count(*) FROM venues WHERE price_level IS NOT NULL'));
        self::assertSame(0, $this->rows(<<<'SQL'
            SELECT count(*) FROM venues v
            JOIN venue_translations t ON t.venue_id = v.id
            WHERE v.price_level IS NULL AND (
                v.capacity_seated IS NOT NULL OR v.capacity_standing IS NOT NULL OR v.party IS NOT NULL
                OR v.has_curfew IS NOT NULL OR v.curfew_at IS NOT NULL OR v.instagram IS NOT NULL
                OR t.ambience IS NOT NULL OR t.capacity_note IS NOT NULL OR t.party_note IS NOT NULL OR t.opening_hours IS NOT NULL
                OR EXISTS (SELECT 1 FROM venue_terms vt WHERE vt.venue_id = v.id AND vt.term_group IN ('ceremony', 'amenity', 'dining', 'vendor', 'usage', 'language'))
            )
            SQL));
        self::assertSame(30, $this->rows("SELECT count(*) FROM venue_terms WHERE term_group = 'usage'"));
        self::assertGreaterThan(0, $this->rows('SELECT count(*) FROM venues WHERE has_curfew IS FALSE'));
        self::assertGreaterThan(0, $this->rows('SELECT count(*) FROM venues WHERE has_curfew AND curfew_at IS NOT NULL'));
        self::assertGreaterThan(0, $this->rows('SELECT count(*) FROM venues WHERE has_curfew AND curfew_at IS NULL'));
        self::assertGreaterThan(0, $this->rows('SELECT count(*) FROM venues WHERE price_level IS NOT NULL AND has_curfew IS NULL'));
    }

    private function assertPhotos(): void
    {
        $gallery = array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT count(p.id) FROM venues v LEFT JOIN venue_photos p ON p.venue_id = v.id AND p.room_id IS NULL GROUP BY v.id',
        ));
        self::assertCount(DemoStory::VENUES, $gallery);
        \assert([] !== $gallery);
        self::assertGreaterThanOrEqual(3, min($gallery));
        self::assertSame(8, max($gallery));
        self::assertSame(0, $this->rows(
            'SELECT count(*) FROM (SELECT venue_id, room_id FROM venue_photos GROUP BY venue_id, room_id HAVING max(position) <> count(*) OR min(position) <> 1) gaps',
        ));
        self::assertGreaterThan(0, $this->rows('SELECT count(*) FROM venue_photos WHERE room_id IS NOT NULL'));
        $withAlt = $this->rows("SELECT count(DISTINCT p.id) FROM venue_photos p JOIN venue_photo_translations t ON t.venue_photo_id = p.id AND t.locale = 'de'");
        self::assertGreaterThan(0, $withAlt);
        self::assertLessThan($this->rows('SELECT count(*) FROM venue_photos'), $withAlt);
        self::assertGreaterThanOrEqual(4, $this->rows('SELECT count(DISTINCT round(width::numeric / height, 2)) FROM venue_photos'));
        self::assertGreaterThan(0, $this->rows("SELECT count(*) FROM venue_photos WHERE variants -> 'widths' @> '[1920]'"));

        $rows = $this->connection->fetchAllAssociative('SELECT p.id, v.number, p.width, p.height, p.variants FROM venue_photos p JOIN venues v ON v.id = p.venue_id');
        foreach ($rows as $row) {
            $variants = ImageVariants::fromJson((string) $row['variants']);
            foreach ($variants->widths as $width) {
                foreach ($variants->formats as $format) {
                    self::assertFileExists($this->paths->variantFile((int) $row['number'], (string) $row['id'], $width, $format));
                }
            }
        }
        $row = $rows[0];
        self::assertSame(
            [640, (int) round(640 * (int) $row['height'] / (int) $row['width'])],
            MediaFiles::size($this->paths->variantFile((int) $row['number'], (string) $row['id'], 640, ImageFormat::Jpeg)),
        );

        $avifs = glob($this->paths->publicDirectory().'/*/*-640.avif') ?: [];
        self::assertNotEmpty($avifs);
        foreach ($avifs as $file) {
            self::assertLessThanOrEqual(40 * 1024, filesize($file), basename($file).' exceeds the 40 KB card budget');
        }
    }

    private function assertDocuments(): void
    {
        $documents = $this->connection->fetchAllAssociative('SELECT d.id, d.path, d.size_bytes, v.number FROM venue_documents d JOIN venues v ON v.id = d.venue_id');
        self::assertNotEmpty($documents);
        foreach ($documents as $document) {
            $published = $this->paths->documentFile((int) $document['number'], (string) $document['id']);
            self::assertFileExists($published);
            self::assertStringStartsWith('%PDF-', (string) file_get_contents($published));
            self::assertSame((int) $document['size_bytes'], filesize($this->paths->original((string) $document['path'])));
        }
    }

    private function media(): DemoMedia
    {
        $media = self::getContainer()->get(DemoMedia::class);
        self::assertInstanceOf(DemoMedia::class, $media);

        return $media;
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

        return new DemoStory($venues, $terms, $towns, $this->media());
    }

    private function rows(string $sql): int
    {
        return (int) $this->connection->fetchOne($sql);
    }
}
