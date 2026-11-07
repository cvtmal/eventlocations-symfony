<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Factory\MediaRightsGrantFactory;
use App\Factory\RoomFactory;
use App\Factory\RoomTranslationFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenueTranslationFactory;
use App\Media\ValueObject\Picture;
use App\Search\ReadModel\CardPhoto;
use App\Search\ReadModel\HomeHero;
use App\Search\ReadModel\HomeHeroQuery;
use App\Search\ReadModel\ListingCard;
use App\Search\ReadModel\ListingQuery;
use App\Search\ReadModel\SearchCriteria;
use App\Search\ReadModel\SimilarVenue;
use App\Search\ReadModel\SimilarVenuesQuery;
use App\Shared\Translation\TranslationJoin;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Integration\Search\ListingQueryTest;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Entity\Venue;
use App\Venues\Entity\VenuePhoto;
use App\Venues\PublicPhotoPolicy;
use App\Venues\ReadModel\DetailRoom;
use App\Venues\ReadModel\VenueDetail;
use App\Venues\ReadModel\VenueDetailQuery;
use App\Venues\ReadModel\VenueSitemapQuery;
use App\Venues\ValueObject\Grantor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * G1's done-when, proven on every public read model with the same venue: a stored photo is public
 * only with a live grant that allows the listing and resized copies. The venue has photos of every
 * kind (granted, never granted, revoked, a grant without the listing, a grant without resized
 * copies), in the gallery and in two rooms. Each test runs in a rolled-back transaction.
 *
 * The preview (`detail(includeUnpublished: true)`) is the one reader that shows them all.
 */
final class PublicPhotoPolicyTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;

    private const string HASH = 'cfbf9375b66ff088996ab8a64d99552e0caac0dcd0ca86189a54e4858c8b96d1';
    private const string BASE = '2026-01-01 10:00:00+00';

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

    public function test_a_listing_card_shows_only_granted_venue_photos(): void
    {
        $venue = $this->venue();

        $cards = $this->listing()->results(SearchCriteria::none(), 'de')->cards;

        self::assertCount(1, $cards);
        self::assertSame($venue->number(), $cards[0]->number);
        self::assertSame($this->granted($venue), $this->cardPhotoIds($cards[0]->photos), 'room photos never reach a card');
    }

    public function test_a_venue_without_a_granted_photo_stays_listed_with_no_photo(): void
    {
        $venue = $this->venue(rooms: false);
        $this->connection->executeStatement('UPDATE media_rights_grants SET revoked_at = now(), revoked_by_type = ?, revoked_by_ref = ? WHERE venue_id = ?', ['staff', 'test', $venue->id()]);

        $page = $this->listing()->results(SearchCriteria::none(), 'de');

        self::assertSame(1, $page->total);
        self::assertSame([], $page->cards[0]->photos);
    }

    public function test_the_photo_limit_of_a_card_counts_granted_photos_only(): void
    {
        $venue = $this->venue();
        [$first] = $this->granted($venue);

        $byNumber = $this->listing()->cardsByNumbers([$venue->number()], 'de', 1);
        $head = $this->listing()->first(3, 1, 'de');

        self::assertSame([$first], $this->cardPhotoIds($byNumber[0]->photos), 'the ungranted photo at position 1 does not use up the limit');
        self::assertSame([$first], $this->cardPhotoIds($head[0]->photos));
    }

    public function test_the_merkliste_shows_only_granted_photos(): void
    {
        $saved = $this->venue();
        $other = VenueFactory::createOne(['name' => 'Andere Location', 'town' => DetailVenues::town('Bern')]);
        VenueTranslationFactory::createOne(['venue' => $other, 'slug' => 'andere-location']);
        VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $other, 'position' => 1]);

        $cards = $this->listing()->cardsByNumbers([$other->number(), $saved->number()], 'de');

        self::assertSame(['Andere Location', 'Seehaus Policy'], array_map(static fn (ListingCard $card): string => $card->name, $cards));
        self::assertSame([], $cards[0]->photos);
        self::assertSame($this->granted($saved), $this->cardPhotoIds($cards[1]->photos));
    }

    public function test_the_similar_venues_carry_the_first_granted_photo(): void
    {
        $venue = $this->venue();
        $none = VenueFactory::createOne(['name' => 'Nur ohne Freigabe', 'town' => DetailVenues::town('Zürich')]);
        VenueTranslationFactory::createOne(['venue' => $none, 'slug' => 'nur-ohne-freigabe']);
        VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $none, 'position' => 1]);
        $me = VenueFactory::createOne(['name' => 'Ich', 'town' => DetailVenues::town('Zürich')]);
        VenueTranslationFactory::createOne(['venue' => $me, 'slug' => 'ich']);

        $similar = $this->similar()->forVenue($me->id(), 'de');

        $byName = array_column(array_map(static fn (SimilarVenue $v): array => ['name' => $v->name, 'venue' => $v], $similar), 'venue', 'name');
        self::assertSame($this->granted($venue)[0], $byName['Seehaus Policy']->photo?->photoId, 'position 1 is not granted, so the card takes position 2');
        self::assertNull($byName['Nur ohne Freigabe']->photo);
    }

    public function test_the_hero_photo_needs_a_live_grant(): void
    {
        $venue = $this->venue(rooms: false);
        $hero = VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 7, 'originalPath' => 'content/some-key/'.self::HASH.'.jpg']);
        $query = new HomeHeroQuery($this->connection, $this->translationJoin(), ListingQueryTest::enabledLocale());

        $found = $query->photo(self::HASH, 'de');
        self::assertNull($found, 'stored, never granted');

        MediaRightsGrantFactory::createOne(['photo' => $hero]);
        $found = $query->photo(self::HASH, 'de');
        self::assertSame($hero->id(), $found?->photo->photoId);

        $this->connection->executeStatement('UPDATE media_rights_grants SET scope_resized_copies = FALSE WHERE venue_photo_id = ?', [$hero->id()]);
        $found = $query->photo(self::HASH, 'de');
        self::assertNull($found, 'no right to resized copies');

        $this->connection->executeStatement('UPDATE media_rights_grants SET scope_resized_copies = TRUE, scope_listing = FALSE WHERE venue_photo_id = ?', [$hero->id()]);
        $found = $query->photo(self::HASH, 'de');
        self::assertNull($found, 'no right to the listing');

        $this->connection->executeStatement('UPDATE media_rights_grants SET scope_listing = TRUE WHERE venue_photo_id = ?', [$hero->id()]);
        $found = $query->photo(self::HASH, 'de');
        self::assertSame($hero->id(), $found?->photo->photoId);

        $this->connection->executeStatement('UPDATE media_rights_grants SET revoked_at = now(), revoked_by_type = ?, revoked_by_ref = ? WHERE venue_photo_id = ?', ['staff', 'test', $hero->id()]);
        $found = $query->photo(self::HASH, 'de');
        self::assertNull($found, 'revoked');
    }

    public function test_the_hero_fallback_skips_a_venue_whose_photos_are_not_granted(): void
    {
        $first = $this->venue(rooms: false);
        $this->connection->executeStatement('UPDATE media_rights_grants SET revoked_at = now(), revoked_by_type = ?, revoked_by_ref = ? WHERE venue_id = ?', ['staff', 'test', $first->id()]);
        $second = VenueFactory::createOne(['name' => 'Mit Freigabe', 'town' => DetailVenues::town('Bern')]);
        VenueTranslationFactory::createOne(['venue' => $second, 'slug' => 'mit-freigabe']);
        $photo = VenuePhotoFactory::createOne(['venue' => $second, 'position' => 1]);

        $hero = HomeHero::fromCards($this->listing()->first(3, 1, 'de'));

        self::assertSame('Mit Freigabe', $hero?->venueName);
        self::assertSame($photo->id(), $hero->photo->photoId);
    }

    public function test_the_public_detail_page_shows_only_granted_photos(): void
    {
        $venue = $this->venue();
        $granted = $this->granted($venue);

        $detail = $this->detail($venue);

        self::assertSame($granted, $this->pictureIds($detail->photos));
        self::assertSame([$this->grantedHall($venue), null], array_map(static fn (DetailRoom $room): ?string => $room->photo?->photoId, $detail->rooms), 'the room picture is its first granted photo; a room without one has none');
        self::assertSame(
            array_map(static fn (string $id): string => '/media/'.$venue->number().'/'.$id.'-1280.jpg', \array_slice($granted, 0, 2)),
            $detail->imageUrls(5),
            'og:image and JSON-LD come from the same list',
        );
    }

    public function test_the_preview_of_staff_and_managers_shows_every_stored_photo(): void
    {
        $venue = $this->venue();

        $detail = $this->detail($venue, includeUnpublished: true);

        self::assertSame($this->stored($venue), $this->pictureIds($detail->photos));
        self::assertSame([$this->hallPhotos($venue)[0], $this->cellarPhotos($venue)[0]], array_map(static fn (DetailRoom $room): ?string => $room->photo?->photoId, $detail->rooms));
    }

    public function test_the_sitemap_lists_only_granted_photos(): void
    {
        $venue = $this->venue();

        $images = $this->sitemap()->page(1)[0]->imageUrls;

        self::assertSame(
            array_map(static fn (string $id): string => '/media/'.$venue->number().'/'.$id.'-1280.jpg', [...$this->granted($venue), $this->grantedHall($venue)]),
            $images,
        );
    }

    public function test_a_photo_that_is_not_public_does_not_move_the_sitemap_lastmod(): void
    {
        $venue = $this->venue();
        $this->setUpdatedAt('venues', 'id', $venue->id(), self::BASE);
        $this->setUpdatedAt('venue_translations', 'venue_id', $venue->id(), self::BASE);
        $this->setUpdatedAt('venue_photos', 'venue_id', $venue->id(), self::BASE);

        foreach ($this->notPublic($venue) as $photoId) {
            $this->setUpdatedAt('venue_photos', 'id', $photoId, '2030-01-01 10:00:00+00');
        }
        self::assertSame('2026-01-01T10:00:00+00:00', $this->sitemap()->page(1)[0]->lastmod->format('c'));
        self::assertSame('2026-01-01T10:00:00+00:00', $this->sitemap()->newestChange()?->format('c'));
        self::assertSame('2026-01-01T10:00:00+00:00', $this->sitemap()->files()[0]->lastmod->format('c'));

        $this->setUpdatedAt('venue_photos', 'id', $this->granted($venue)[1], '2029-06-01 10:00:00+00');
        self::assertSame('2029-06-01T10:00:00+00:00', $this->sitemap()->page(1)[0]->lastmod->format('c'));
    }

    public function test_filter_keeps_the_public_ids_in_the_order_given(): void
    {
        $venue = $this->venue();
        [$a, $b] = $this->granted($venue);
        $hall = $this->grantedHall($venue);
        $unknown = Uuid::v7()->toRfc4122();

        $filtered = $this->policy()->filter([$hall, $unknown, $this->stored($venue)[0], $b, $a, ...$this->notPublic($venue)]);

        self::assertSame([$hall, $b, $a], $filtered);
        self::assertSame([], $this->policy()->filter([]));
        self::assertSame([], $this->policy()->filter($this->notPublic($venue)));
    }

    public function test_filter_drops_an_id_that_is_no_uuid_instead_of_asking_postgres_about_it(): void
    {
        // Postgres answers a text it cannot read as a UUID with SQLSTATE 22P02, which would be a 500.
        $venue = $this->venue();
        [$a, $b] = $this->granted($venue);
        $junk = ['', 'x', 'not-a-uuid', "1'; DROP TABLE venue_photos; --", substr($a, 0, 35), $a.'0', $a."\n", "\0", '0199a1b2-0000-7000-8000-00000000000g'];

        self::assertSame([$b, $a], $this->policy()->filter([...$junk, $b, ...$junk, $a, ...$junk]), 'the junk is dropped and the order of the rest is kept');
        self::assertSame([], $this->policy()->filter($junk), 'nothing but junk asks the database nothing');
    }

    public function test_the_predicate_works_under_the_alias_a_caller_chooses(): void
    {
        $venue = $this->venue();
        $sql = 'SELECT ph.id FROM venue_photos ph WHERE ph.venue_id = :venue AND '.PublicPhotoPolicy::sql('ph').' ORDER BY ph.room_id NULLS FIRST, ph.position';

        $ids = array_map(strval(...), $this->connection->fetchFirstColumn($sql, ['venue' => $venue->id()]));

        self::assertSame([...$this->granted($venue), $this->grantedHall($venue)], $ids);
    }

    public function test_an_old_revoked_grant_beside_a_live_one_does_not_hide_the_photo(): void
    {
        // The app never leaves this shape (revoking removes the photo), but the schema allows it
        // (the unique index covers live grants only) and the predicate asks for a live grant, not
        // for the absence of a revoked one.
        $venue = $this->venue(rooms: false);
        $before = $this->pictureIds($this->detail($venue)->photos);
        $photo = $this->em->getRepository(VenuePhoto::class)->find($before[0]);
        self::assertInstanceOf(VenuePhoto::class, $photo);
        MediaRightsGrantFactory::new()->revoked(Grantor::staff('019a0000-0000-7000-8000-0000000000bb'))->create(['photo' => $photo]);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT count(*) FROM media_rights_grants WHERE venue_photo_id = ?', [$photo->id()]));

        self::assertSame($before, $this->pictureIds($this->detail($venue)->photos));
    }

    /**
     * A published venue in Zürich with the photos of every kind. Positions in the gallery:
     * 1 never granted, 2 granted, 3 revoked, 4 without the listing, 5 without resized copies,
     * 6 granted. The first room has 1 never granted, 2 granted; the second only a never granted one.
     */
    private function venue(bool $rooms = true): Venue
    {
        $venue = VenueFactory::createOne(['name' => 'Seehaus Policy', 'town' => DetailVenues::town('Zürich')]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'slug' => 'seehaus-policy']);

        $photo = static fn (int $position, ?object $room = null): VenuePhoto => VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'room' => $room, 'position' => $position]);
        $photo(1);
        VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 2]);
        MediaRightsGrantFactory::new()->revoked()->create(['photo' => $photo(3)]);
        $noListing = $photo(4);
        MediaRightsGrantFactory::createOne(['photo' => $noListing]);
        $this->connection->executeStatement('UPDATE media_rights_grants SET scope_listing = FALSE WHERE venue_photo_id = ?', [$noListing->id()]);
        $noCopies = $photo(5);
        MediaRightsGrantFactory::createOne(['photo' => $noCopies]);
        $this->connection->executeStatement('UPDATE media_rights_grants SET scope_resized_copies = FALSE WHERE venue_photo_id = ?', [$noCopies->id()]);
        VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 6]);

        if ($rooms) {
            $hall = RoomFactory::createOne(['venue' => $venue, 'position' => 1]);
            RoomTranslationFactory::createOne(['room' => $hall, 'name' => 'Saal']);
            $cellar = RoomFactory::createOne(['venue' => $venue, 'position' => 2]);
            RoomTranslationFactory::createOne(['room' => $cellar, 'name' => 'Keller']);
            $photo(1, $hall);
            VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $hall, 'position' => 2]);
            $photo(1, $cellar);
        }

        return $venue;
    }

    /**
     * @return list<string> the gallery photos that are public, by position
     */
    private function granted(Venue $venue): array
    {
        return $this->ids($venue, 'room_id IS NULL AND position IN (2, 6)');
    }

    /**
     * @return list<string> every stored photo that is shown in the preview: gallery by position
     */
    private function stored(Venue $venue): array
    {
        return $this->ids($venue, 'room_id IS NULL');
    }

    /**
     * @return list<string> the room photos of the first room, by position
     */
    private function hallPhotos(Venue $venue): array
    {
        return $this->ids($venue, 'room_id = (SELECT id FROM rooms WHERE venue_id = venue_photos.venue_id AND position = 1)');
    }

    /**
     * @return list<string> the room photos of the second room, by position
     */
    private function cellarPhotos(Venue $venue): array
    {
        return $this->ids($venue, 'room_id = (SELECT id FROM rooms WHERE venue_id = venue_photos.venue_id AND position = 2)');
    }

    private function grantedHall(Venue $venue): string
    {
        return $this->hallPhotos($venue)[1];
    }

    /**
     * @return list<string> the photos of the venue that are stored and not public
     */
    private function notPublic(Venue $venue): array
    {
        return $this->ids($venue, '(room_id IS NULL AND position IN (1, 3, 4, 5)) OR (room_id IS NOT NULL AND position = 1)');
    }

    /**
     * @return list<string>
     */
    private function ids(Venue $venue, string $where): array
    {
        return array_map(strval(...), $this->connection->fetchFirstColumn(
            'SELECT id FROM venue_photos WHERE venue_id = :venue AND ('.$where.') ORDER BY room_id NULLS FIRST, position',
            ['venue' => $venue->id()],
        ));
    }

    /**
     * @param list<CardPhoto> $photos
     *
     * @return list<string>
     */
    private function cardPhotoIds(array $photos): array
    {
        return array_map(static fn (CardPhoto $photo): string => $photo->photoId, $photos);
    }

    /**
     * @param list<Picture> $pictures
     *
     * @return list<string>
     */
    private function pictureIds(array $pictures): array
    {
        return array_map(static fn (Picture $picture): string => $picture->photoId, $pictures);
    }

    private function detail(Venue $venue, bool $includeUnpublished = false): VenueDetail
    {
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        $locale = ListingQueryTest::enabledLocale();
        $detail = new VenueDetailQuery($this->connection, new TranslationJoin($locale), $locale, $translator)->detail($venue->number(), 'de', $includeUnpublished);
        self::assertNotNull($detail);

        return $detail;
    }

    private function listing(): ListingQuery
    {
        $locale = ListingQueryTest::enabledLocale();

        return new ListingQuery($this->connection, new TranslationJoin($locale), $locale, new MockClock('2026-10-30 10:00:00', 'UTC'));
    }

    private function similar(): SimilarVenuesQuery
    {
        $locale = ListingQueryTest::enabledLocale();

        return new SimilarVenuesQuery($this->connection, new TranslationJoin($locale), $locale);
    }

    private function sitemap(): VenueSitemapQuery
    {
        return new VenueSitemapQuery($this->connection, ListingQueryTest::enabledLocale());
    }

    private function policy(): PublicPhotoPolicy
    {
        return new PublicPhotoPolicy($this->connection);
    }

    private function translationJoin(): TranslationJoin
    {
        return new TranslationJoin(ListingQueryTest::enabledLocale());
    }

    private function setUpdatedAt(string $table, string $column, string $value, string $at): void
    {
        \assert(\in_array($table, ['venues', 'venue_translations', 'venue_photos'], true));
        \assert(\in_array($column, ['id', 'venue_id'], true));
        $this->connection->executeStatement(\sprintf('UPDATE %s SET updated_at = ? WHERE %s = ?', $table, $column), [$at, $value]);
    }
}
