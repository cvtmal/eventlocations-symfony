<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenueTranslationFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Integration\Search\ListingQueryTest;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Entity\Venue;
use App\Venues\ReadModel\SitemapFile;
use App\Venues\ReadModel\SitemapVenue;
use App\Venues\ReadModel\VenueSitemapQuery;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

final class VenueSitemapQueryTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;

    private EntityManagerInterface $em;
    private Connection $connection;
    private VenueSitemapQuery $query;

    protected function setUp(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $this->query = new VenueSitemapQuery($this->connection, ListingQueryTest::enabledLocale());
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_only_published_venues_with_a_default_translation_appear(): void
    {
        DetailVenues::minimal('veroeffentlicht', 'Veröffentlicht');
        VenueTranslationFactory::createOne([
            'venue' => VenueFactory::createOne(['status' => VenueStatus::Unpublished, 'town' => DetailVenues::town('Bern')]),
            'slug' => 'entwurf',
        ]);
        VenueFactory::createOne(['status' => VenueStatus::Published, 'town' => DetailVenues::town('Bern')]);

        self::assertSame(['veroeffentlicht'], $this->slugs($this->query->page(1)));
    }

    public function test_lastmod_follows_the_translation(): void
    {
        $venue = DetailVenues::minimal();
        $this->setUpdatedAt('venues', 'id', $venue->id(), '2026-01-01 10:00:00+00');
        $this->setUpdatedAt('venue_translations', 'venue_id', $venue->id(), '2026-03-01 10:00:00+00');

        self::assertSame('2026-03-01T10:00:00+00:00', $this->query->page(1)[0]->lastmod->format('c'));
    }

    public function test_lastmod_follows_the_photos(): void
    {
        $venue = DetailVenues::minimal();
        VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        $this->setUpdatedAt('venues', 'id', $venue->id(), '2026-01-01 10:00:00+00');
        $this->setUpdatedAt('venue_translations', 'venue_id', $venue->id(), '2026-01-02 10:00:00+00');
        $this->setUpdatedAt('venue_photos', 'venue_id', $venue->id(), '2026-04-01 10:00:00+00');

        self::assertSame('2026-04-01T10:00:00+00:00', $this->query->page(1)[0]->lastmod->format('c'));
    }

    public function test_lastmod_follows_the_venue(): void
    {
        $venue = DetailVenues::minimal();
        $this->setUpdatedAt('venue_translations', 'venue_id', $venue->id(), '2026-01-02 10:00:00+00');
        $this->setUpdatedAt('venues', 'id', $venue->id(), '2026-05-01 10:00:00+00');

        self::assertSame('2026-05-01T10:00:00+00:00', $this->query->page(1)[0]->lastmod->format('c'));
    }

    public function test_files_page_by_venue_number(): void
    {
        $venues = $this->fiveVenues();
        $this->setUpdatedAt('venues', 'id', $venues[1]->id(), '2030-01-01 10:00:00+00');

        $files = $this->query->files(2);

        self::assertSame([1, 2, 3], array_map(static fn (SitemapFile $file): int => $file->number, $files));
        self::assertSame('2030-01-01T10:00:00+00:00', $files[0]->lastmod->format('c'));
        self::assertNotSame('2030-01-01T10:00:00+00:00', $files[1]->lastmod->format('c'));
    }

    public function test_a_page_holds_the_venues_of_its_file_by_number(): void
    {
        $this->fiveVenues();

        self::assertSame(['venue-3', 'venue-4'], $this->slugs($this->query->page(2, 2)));
        self::assertSame(['venue-5'], $this->slugs($this->query->page(3, 2)));
        self::assertSame([], $this->query->page(4, 2));
    }

    public function test_images_are_share_paths_with_venue_photos_first(): void
    {
        $venue = DetailVenues::full($this->em);

        $images = $this->query->page(1)[0]->imageUrls;

        self::assertCount(5, $images);
        foreach ($images as $image) {
            self::assertMatchesRegularExpression('#^/media/'.$venue->number().'/[0-9a-f-]+-1280\.jpg$#', $image);
        }
        self::assertSame($this->venuePhotoId($venue), substr($images[0], \strlen('/media/'.$venue->number().'/'), 36));
    }

    public function test_a_venue_without_photos_has_no_images(): void
    {
        DetailVenues::minimal();

        self::assertSame([], $this->query->page(1)[0]->imageUrls);
    }

    public function test_newest_change_is_null_without_venues(): void
    {
        self::assertNull($this->query->newestChange());
    }

    public function test_newest_change_is_the_newest_venue(): void
    {
        $venues = $this->fiveVenues();
        $this->setUpdatedAt('venues', 'id', $venues[3]->id(), '2030-01-01 10:00:00+00');

        self::assertSame('2030-01-01T10:00:00+00:00', $this->query->newestChange()?->format('c'));
    }

    /**
     * @return list<Venue>
     */
    private function fiveVenues(): array
    {
        $venues = [];
        for ($i = 1; $i <= 5; ++$i) {
            $venues[] = DetailVenues::minimal('venue-'.$i, 'Venue '.$i);
        }

        return $venues;
    }

    /**
     * @param list<SitemapVenue> $venues
     *
     * @return list<string>
     */
    private function slugs(array $venues): array
    {
        return array_map(static fn (SitemapVenue $venue): string => $venue->slug, $venues);
    }

    private function setUpdatedAt(string $table, string $column, string $id, string $at): void
    {
        $this->em->flush();
        $this->connection->executeStatement(\sprintf('UPDATE %s SET updated_at = :at WHERE %s = :id', $table, $column), ['at' => $at, 'id' => $id]);
    }

    private function venuePhotoId(Venue $venue): string
    {
        $id = $this->connection->fetchOne(
            'SELECT id FROM venue_photos WHERE venue_id = :id AND room_id IS NULL AND position = 1',
            ['id' => $venue->id()],
        );
        self::assertIsString($id);

        return $id;
    }
}
