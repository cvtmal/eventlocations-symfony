<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenueTranslationFactory;
use App\Geo\ValueObject\Canton;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Integration\Search\ListingQueryTest;
use App\Tests\Support\Search\LandingFixtures;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\EntitlementResolver;
use App\Venues\Entity\Venue;
use App\Venues\ReadModel\IndexedVenue;
use App\Venues\ReadModel\SitemapFile;
use App\Venues\ReadModel\SitemapVenue;
use App\Venues\ReadModel\VenueSitemapQuery;
use App\Venues\ValueObject\KnownTiers;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Zenstruck\Foundry\Test\Factories;

final class VenueSitemapQueryTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;
    use LandingFixtures;

    private const string BASE = '2026-01-01 10:00:00+00';
    private const string BASE_C = '2026-01-01T10:00:00+00:00';

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
        $this->query = new VenueSitemapQuery($this->connection, ListingQueryTest::enabledLocale(), new EntitlementResolver($this->connection), new MockClock('2026-11-07 10:00:00'));
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

    public function test_a_liechtenstein_venue_appears(): void
    {
        DetailVenues::inCantonOnly('remise-vaduz', 'Remise Vaduz', 'li');

        self::assertSame(['remise-vaduz'], $this->slugs($this->query->page(1)));
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

    /**
     * F12: the sitemap advertises what the page shows. Free is cut to its photo limit, in the page's order
     * (gallery by position, then the rooms); Pro lists all nine (eight gallery photos and one room photo).
     */
    public function test_a_free_venue_lists_only_the_photos_its_plan_shows(): void
    {
        $free = DetailVenues::overfull(KnownTiers::FREE, 'haus-klein', 'Haus Klein');
        $pro = DetailVenues::overfull(KnownTiers::PRO, 'haus-gross', 'Haus Gross');

        $images = [];
        foreach ($this->query->page(1) as $venue) {
            $images[$venue->slug] = $venue->imageUrls;
        }

        self::assertCount(6, $images['haus-klein']);
        self::assertCount(DetailVenues::OVERFULL_GALLERY + 1, $images['haus-gross']);
        self::assertSame(\array_slice($this->galleryIds($free), 0, 6), array_map(static fn (string $image): string => substr($image, \strlen('/media/'.$free->number().'/'), 36), $images['haus-klein']));
        self::assertSame($this->galleryIds($pro), array_map(static fn (string $image): string => substr($image, \strlen('/media/'.$pro->number().'/'), 36), \array_slice($images['haus-gross'], 0, DetailVenues::OVERFULL_GALLERY)));
    }

    public function test_a_plan_edit_moves_the_cut_of_the_sitemap_at_once(): void
    {
        DetailVenues::overfull(KnownTiers::FREE, 'haus-klein', 'Haus Klein');
        self::assertCount(6, $this->query->page(1)[0]->imageUrls);

        $this->connection->executeStatement("UPDATE tiers SET photos_max = 2 WHERE code = 'free'");

        self::assertCount(2, $this->query->page(1)[0]->imageUrls);
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

    public function test_newest_change_by_group_is_empty_without_venues(): void
    {
        $changes = $this->query->newestChangeByGroup();

        self::assertSame([], $changes->cantons);
        self::assertSame([], $changes->towns);
        self::assertSame([], $changes->buildings);
    }

    public function test_newest_change_by_group_is_the_newest_venue_of_each_canton_town_and_building(): void
    {
        $staefa = $this->withBuilding($this->inTown('Stäfa', 2), 'castle');
        $zurichOnly = $this->inCanton(Canton::ZH, 1);
        $bern = $this->withBuilding($this->inCanton(Canton::BE, 2), 'hotel');
        $this->withBuilding([$bern[0]], 'castle');
        $this->backdateAll();
        $this->setUpdatedAt('venues', 'id', $staefa[1]->id(), '2026-02-01 10:00:00+00');
        $this->setUpdatedAt('venues', 'id', $zurichOnly[0]->id(), '2026-03-01 10:00:00+00');
        $this->setUpdatedAt('venues', 'id', $bern[1]->id(), '2026-04-01 10:00:00+00');
        $this->setUpdatedAt('venues', 'id', $bern[0]->id(), '2026-01-15 10:00:00+00');

        $changes = $this->query->newestChangeByGroup();

        self::assertSame(['be' => '2026-04-01T10:00:00+00:00', 'zh' => '2026-03-01T10:00:00+00:00'], $this->formatted($changes->cantons));
        self::assertSame([$this->town('Stäfa')->id() => '2026-02-01T10:00:00+00:00'], $this->formatted($changes->towns));
        self::assertSame(['castle' => '2026-02-01T10:00:00+00:00', 'hotel' => '2026-04-01T10:00:00+00:00'], $this->formatted($changes->buildings));
    }

    public function test_newest_change_by_group_ignores_unpublished_and_untranslated_venues(): void
    {
        $this->withBuilding($this->inTown('Stäfa', 1), 'castle');
        $unpublished = $this->withBuilding($this->inCanton(Canton::ZH, 1, VenueStatus::Unpublished), 'castle');
        $hidden = $this->withBuilding($this->inCanton(Canton::BE, 1, VenueStatus::Unpublished), 'hotel');
        $untranslated = VenueFactory::createOne(['town' => $this->town('Stäfa')]);
        $this->withBuilding([$untranslated], 'castle');
        $this->backdateAll();
        $this->setUpdatedAt('venues', 'id', $unpublished[0]->id(), '2030-01-01 10:00:00+00');
        $this->setUpdatedAt('venues', 'id', $hidden[0]->id(), '2030-01-01 10:00:00+00');
        $this->setUpdatedAt('venues', 'id', $untranslated->id(), '2030-01-01 10:00:00+00');

        $changes = $this->query->newestChangeByGroup();

        self::assertSame(['zh' => '2026-01-01T10:00:00+00:00'], $this->formatted($changes->cantons));
        self::assertSame([$this->town('Stäfa')->id() => '2026-01-01T10:00:00+00:00'], $this->formatted($changes->towns));
        self::assertSame(['castle' => '2026-01-01T10:00:00+00:00'], $this->formatted($changes->buildings));
    }

    public function test_newest_change_by_group_follows_the_translation_and_the_photos(): void
    {
        $staefa = $this->withBuilding($this->inTown('Stäfa', 2), 'castle');
        VenuePhotoFactory::createOne(['venue' => $staefa[1], 'position' => 1]);
        $bern = $this->withBuilding($this->inCanton(Canton::BE, 1), 'hotel');
        $this->backdateAll();

        $this->setUpdatedAt('venue_translations', 'venue_id', $staefa[0]->id(), '2026-03-01 10:00:00+00');
        $changes = $this->query->newestChangeByGroup();
        self::assertSame('2026-03-01T10:00:00+00:00', $changes->cantons['zh']->format('c'));
        self::assertSame('2026-03-01T10:00:00+00:00', $changes->towns[$this->town('Stäfa')->id()]->format('c'));
        self::assertSame('2026-03-01T10:00:00+00:00', $changes->buildings['castle']->format('c'));
        self::assertSame(self::BASE_C, $changes->buildings['hotel']->format('c'));

        $this->setUpdatedAt('venue_photos', 'venue_id', $staefa[1]->id(), '2026-05-01 10:00:00+00');
        $changes = $this->query->newestChangeByGroup();
        self::assertSame('2026-05-01T10:00:00+00:00', $changes->cantons['zh']->format('c'));
        self::assertSame('2026-05-01T10:00:00+00:00', $changes->towns[$this->town('Stäfa')->id()]->format('c'));
        self::assertSame('2026-05-01T10:00:00+00:00', $changes->buildings['castle']->format('c'));
        self::assertSame(self::BASE_C, $changes->cantons['be']->format('c'));

        $this->setUpdatedAt('venue_translations', 'venue_id', $bern[0]->id(), '2026-06-01 10:00:00+00');
        self::assertSame('2026-06-01T10:00:00+00:00', $this->query->newestChangeByGroup()->buildings['hotel']->format('c'));
    }

    public function test_the_index_lists_the_sitemaps_venues_only(): void
    {
        DetailVenues::minimal('veroeffentlicht', 'Veröffentlicht');
        VenueTranslationFactory::createOne([
            'venue' => VenueFactory::createOne(['status' => VenueStatus::Unpublished, 'town' => DetailVenues::town('Bern')]),
            'slug' => 'entwurf',
        ]);
        VenueFactory::createOne(['status' => VenueStatus::Published, 'town' => DetailVenues::town('Bern')]);

        $index = array_map(static fn (IndexedVenue $venue): string => $venue->slug, $this->query->index());

        self::assertSame(['veroeffentlicht'], $index);
        self::assertSame($this->slugs($this->query->page(1)), $index);
    }

    public function test_the_index_is_sorted_by_name_with_umlauts_in_place(): void
    {
        DetailVenues::minimal('zunfthaus', 'Zunfthaus');
        DetailVenues::minimal('oekonomie', 'Ökonomiegebäude');
        DetailVenues::minimal('alte-muehle', 'Alte Mühle');
        DetailVenues::minimal('orangerie', 'Orangerie');

        self::assertSame(
            ['Alte Mühle', 'Ökonomiegebäude', 'Orangerie', 'Zunfthaus'],
            array_map(static fn (IndexedVenue $venue): string => $venue->name, $this->query->index()),
        );
    }

    public function test_the_index_carries_the_place_and_the_capacity(): void
    {
        $venue = DetailVenues::minimal();
        $this->em->flush();

        $indexed = $this->query->index()[0];

        self::assertSame(DetailVenues::MINIMAL_SLUG, $indexed->slug);
        self::assertSame('Gartenhaus Minimal', $indexed->name);
        self::assertSame($venue->zip(), $indexed->zip);
        self::assertSame('Bern', $indexed->townName);
        self::assertSame(Canton::BE, $indexed->canton);
        self::assertSame(80, $indexed->capacityMax);
    }

    public function test_the_index_keeps_a_venue_without_a_town_or_canton(): void
    {
        DetailVenues::inCantonOnly();
        DetailVenues::withoutPlace();

        $index = $this->query->index();

        self::assertSame(['scheune-nur-kanton', 'wiesenhof-ohne-ort'], array_map(static fn (IndexedVenue $venue): string => $venue->slug, $index));
        self::assertNull($index[0]->townName);
        self::assertNull($index[0]->zip);
        self::assertSame(Canton::ZH, $index[0]->canton);
        self::assertNull($index[1]->townName);
        self::assertNull($index[1]->canton);
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

    /** Every venue, translation and photo of the test to one old timestamp, so a single edit decides. */
    private function backdateAll(): void
    {
        $this->em->flush();
        foreach (['venues', 'venue_translations', 'venue_photos'] as $table) {
            $this->connection->executeStatement(\sprintf('UPDATE %s SET updated_at = :at', $table), ['at' => self::BASE]);
        }
    }

    /**
     * @param array<string, \DateTimeImmutable> $changes
     *
     * @return array<string, string>
     */
    private function formatted(array $changes): array
    {
        ksort($changes);

        return array_map(static fn (\DateTimeImmutable $at): string => $at->format('c'), $changes);
    }

    /**
     * @return list<string> the ids of the venue's gallery photos, by position
     */
    private function galleryIds(Venue $venue): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn('SELECT id FROM venue_photos WHERE venue_id = ? AND room_id IS NULL ORDER BY position', [$venue->id()]);

        return $ids;
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
