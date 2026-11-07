<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues\Cms;

use App\Factory\RoomFactory;
use App\Factory\RoomTranslationFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenueTranslationFactory;
use App\Media\ValueObject\Picture;
use App\Shared\Translation\TranslationJoin;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Integration\Search\ListingQueryTest;
use App\Tests\Support\Venues\DetailVenues;
use App\Tests\Support\Venues\PublicPhotos;
use App\Venues\Command\GrantPhotoRights;
use App\Venues\Command\RemoveVenuePhoto;
use App\Venues\Entity\Venue;
use App\Venues\Exception\VenueRowNotFound;
use App\Venues\ReadModel\VenueDetail;
use App\Venues\ReadModel\VenueDetailQuery;
use App\Venues\ReadModel\VenueSitemapQuery;
use App\Venues\ValueObject\GrantEvidence;
use App\Venues\ValueObject\Grantor;
use App\Venues\ValueObject\PhotoGrant;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * G1 end to end on real uploads: staff approve ten photos, revoke two, and nothing of the two is
 * public any more (no read model, no variant file, no unused original) while the evidence of their
 * grants stays. Also the other direction: an upload that carries no grant is stored but private
 * until a GrantPhotoRights covers it, and a revoked grant is never revived.
 */
final class RevokePhotoRightsTest extends KernelTestCase
{
    use Factories;
    use HandlesVenueMedia;
    use ImportsTownDataset;

    private const string ADMIN = '019a0000-0000-7000-8000-0000000000ad';

    public function test_approve_ten_photos_and_revoke_two(): void
    {
        $venue = $this->venue('Seehaus Revoke', 'seehaus-revoke');
        $neighbour = $this->venue('Nachbarhaus', 'nachbarhaus');
        $number = $venue->number();
        $photos = [];
        for ($i = 1; $i <= 10; ++$i) {
            $photos[] = $this->uploadGranted($number);
        }
        $ids = array_column($photos, 'id');
        $files = [];
        foreach ($photos as $photo) {
            $files[$photo['id']] = $this->variantFiles($number, $photo['id']);
            self::assertNotSame([], $files[$photo['id']]);
        }

        $before = $this->seen($number, $neighbour);
        self::assertSame($ids, $before['detail']);
        self::assertSame($ids, $before['sitemap']);
        self::assertSame($ids, $before['merkliste']);
        self::assertSame(\array_slice($ids, 0, 8), $before['listing'], 'a card shows its first eight photos');
        self::assertSame([$ids[0]], $before['similar']);

        // The third and the seventh photo, both inside the card's eight.
        $revoked = [$photos[2], $photos[6]];
        $remaining = array_values(array_diff($ids, array_column($revoked, 'id')));
        self::assertCount(8, $remaining);
        $this->purger()->venues = [];
        $this->purger()->listings = 0;

        foreach ($revoked as $photo) {
            $this->dispatch(new RemoveVenuePhoto($number, $photo['id'], Grantor::staff(self::ADMIN)));
        }
        $this->em->clear();

        // Public: the eight that are left, on every reader, and none of the two.
        $after = $this->seen($number, $neighbour);
        self::assertSame($remaining, $after['detail']);
        self::assertSame($remaining, $after['sitemap']);
        self::assertSame($remaining, $after['merkliste']);
        self::assertSame($remaining, $after['listing'], 'the card has its eight again: the two places were refilled from the rest');
        self::assertSame([$remaining[0]], $after['similar']);
        self::assertSame($remaining, $this->scope($venue->id()));
        self::assertSame(range(1, 8), $this->positions($venue->id()));

        // Files: nginx has nothing to serve for the two, and the eight are untouched.
        foreach ($revoked as $photo) {
            self::assertSame([], $this->variantFiles($number, $photo['id']), 'every variant of a revoked photo is gone from /media');
            foreach ($files[$photo['id']] as $file) {
                self::assertFileDoesNotExist($file);
            }
            self::assertFileDoesNotExist($this->paths->original($photo['original']), 'its original was used by no other row');
        }
        foreach ($photos as $photo) {
            if (\in_array($photo['id'], $remaining, true)) {
                self::assertSame($files[$photo['id']], $this->variantFiles($number, $photo['id']));
                self::assertFileExists($this->paths->original($photo['original']));
            }
        }

        // Evidence: ten grant rows, the two revoked ones kept with who and when, the photo link cut.
        $grants = $this->grants($venue->id());
        self::assertCount(10, $grants);
        foreach ($revoked as $photo) {
            $grant = $grants[$photo['original']];
            self::assertNull($grant['venue_photo_id']);
            self::assertNotNull($grant['revoked_at']);
            self::assertSame(['staff', self::ADMIN], [$grant['revoked_by_type'], $grant['revoked_by_ref']]);
            self::assertSame(['staff', self::ADMIN, 'staff_attestation'], [$grant['grantor_type'], $grant['grantor_ref'], $grant['evidence']], 'who granted it is not rewritten by the revocation');
        }
        foreach ($photos as $photo) {
            if (\in_array($photo['id'], $remaining, true)) {
                $grant = $grants[$photo['original']];
                self::assertSame($photo['id'], $grant['venue_photo_id']);
                self::assertNull($grant['revoked_at']);
                self::assertNull($grant['revoked_by_type']);
                self::assertNull($grant['revoked_by_ref']);
            }
        }

        // The cache: each removal purged the venue and the listings.
        self::assertSame([$number, $number], $this->purger()->venues);
        self::assertSame(2, $this->purger()->listings);
        self::assertCount(2, array_filter($this->auditRows($number), static fn (array $row): bool => str_ends_with($row['command'], 'RemoveVenuePhoto')));
    }

    public function test_an_upload_without_a_grant_is_stored_but_not_public_until_it_is_granted(): void
    {
        $venue = $this->venue('Seehaus Ohne Freigabe', 'seehaus-ohne-freigabe');
        $neighbour = $this->venue('Nachbarhaus', 'nachbarhaus');
        $number = $venue->number();

        $photo = $this->addPhoto($number, $this->upload())->photoId;

        self::assertNotSame([], $this->variantFiles($number, $photo), 'the files are there');
        self::assertSame([$photo], $this->scope($venue->id()));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM media_rights_grants WHERE venue_id = ?', [$venue->id()]));
        foreach ($this->seen($number, $neighbour) as $surface => $ids) {
            self::assertSame([], $ids, $surface);
        }
        self::assertSame([$photo], array_map(static fn (Picture $picture): string => $picture->photoId, $this->preview($number)->photos), 'staff and managers see it in the preview');
        $this->purger()->venues = [];
        $this->purger()->listings = 0;

        $granted = $this->dispatch(new GrantPhotoRights($number, Grantor::manager('019a0000-0000-7000-8000-0000000000aa'), GrantEvidence::Declaration));

        self::assertSame(1, $granted);
        $seen = $this->seen($number, $neighbour);
        self::assertSame([], $seen['rooms'], 'a gallery photo is no room picture');
        unset($seen['rooms']);
        foreach ($seen as $surface => $ids) {
            self::assertSame([$photo], $ids, $surface);
        }
        self::assertSame([$number], $this->purger()->venues);
        self::assertSame(1, $this->purger()->listings);
    }

    public function test_a_revoked_grant_is_never_revived_by_uploading_the_same_file_again(): void
    {
        $venue = $this->venue('Seehaus Wieder', 'seehaus-wieder');
        $number = $venue->number();
        $source = $this->upload();
        $first = $this->addPhoto($number, $source, grant: $this->staffGrant())->photoId;
        $this->dispatch(new RemoveVenuePhoto($number, $first, Grantor::staff(self::ADMIN)));
        $this->em->clear();

        $again = $this->addPhoto($number, $source);

        self::assertFalse($again->duplicate);
        self::assertNotSame($first, $again->photoId);
        self::assertSame([], PublicPhotos::of($this->connection, $this->translator(), $number)['detail'], 'the new row has no grant of its own, the old one is over');

        $this->dispatch(new GrantPhotoRights($number, Grantor::staff(self::ADMIN), GrantEvidence::StaffAttestation));
        self::assertSame([$again->photoId], PublicPhotos::of($this->connection, $this->translator(), $number)['detail']);
        $grants = $this->connection->fetchAllAssociative('SELECT venue_photo_id, revoked_at IS NOT NULL AS revoked FROM media_rights_grants WHERE venue_id = ? ORDER BY revoked DESC', [$venue->id()]);
        self::assertSame([[null, true], [$again->photoId, false]], array_map(static fn (array $grant): array => [$grant['venue_photo_id'], $grant['revoked']], $grants));
    }

    public function test_revoking_a_granted_room_photo_takes_it_off_the_room_and_purges_the_venue_and_the_listings(): void
    {
        $venue = $this->venue('Seehaus Saal', 'seehaus-saal');
        $number = $venue->number();
        $room = RoomFactory::createOne(['venue' => $venue, 'position' => 1]);
        RoomTranslationFactory::createOne(['room' => $room, 'name' => 'Saal']);
        $roomId = $room->id();
        $first = $this->addPhoto($number, $this->upload(), $roomId, grant: $this->staffGrant())->photoId;
        $second = $this->addPhoto($number, $this->upload(), $roomId, grant: $this->staffGrant())->photoId;
        self::assertSame([$first], PublicPhotos::of($this->connection, $this->translator(), $number)['rooms'], 'the room shows its first photo');
        self::assertSame([$first, $second], PublicPhotos::of($this->connection, $this->translator(), $number)['sitemap'], 'and the sitemap lists both room photos');
        $this->purger()->venues = [];
        $this->purger()->listings = 0;

        $this->dispatch(new RemoveVenuePhoto($number, $first, Grantor::staff(self::ADMIN)));

        self::assertSame([$second], PublicPhotos::of($this->connection, $this->translator(), $number)['rooms']);
        self::assertSame([$second], PublicPhotos::of($this->connection, $this->translator(), $number)['sitemap']);
        self::assertSame([$number], $this->purger()->venues);
        self::assertSame(1, $this->purger()->listings, 'no card shows a room photo, but the sitemap does, and its purge is the listings purge');
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT count(*) FROM media_rights_grants WHERE venue_id = ?', [$venue->id()]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM media_rights_grants WHERE venue_id = ? AND revoked_at IS NOT NULL AND venue_photo_id IS NULL', [$venue->id()]));
    }

    public function test_removing_a_room_photo_nobody_granted_purges_the_venue_only(): void
    {
        $venue = $this->venue('Seehaus Saal Privat', 'seehaus-saal-privat');
        $number = $venue->number();
        $room = RoomFactory::createOne(['venue' => $venue, 'position' => 1]);
        RoomTranslationFactory::createOne(['room' => $room, 'name' => 'Saal']);
        $roomId = $room->id();
        $waiting = $this->addPhoto($number, $this->upload(), $roomId)->photoId;
        self::assertSame([], PublicPhotos::of($this->connection, $this->translator(), $number)['sitemap'], 'it waits for a grant, so it is public nowhere');
        $this->resetPurger();

        $this->dispatch(new RemoveVenuePhoto($number, $waiting, Grantor::staff(self::ADMIN)));

        self::assertSame([], $this->scope($venue->id(), $roomId));
        self::assertSame([$number], $this->purger()->venues);
        self::assertSame(0, $this->purger()->listings, 'nothing public changed, so only the venue page is purged');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM media_rights_grants WHERE venue_id = ?', [$venue->id()]), 'there was no grant to end');
    }

    public function test_revoking_twice_fails_the_second_time_and_changes_nothing(): void
    {
        $venue = $this->venue('Seehaus Zweimal', 'seehaus-zweimal');
        $number = $venue->number();
        $photo = $this->addPhoto($number, $this->upload(), grant: $this->staffGrant())->photoId;
        $this->dispatch(new RemoveVenuePhoto($number, $photo, Grantor::staff(self::ADMIN)));
        $this->em->clear();
        $grants = $this->connection->fetchAllAssociative('SELECT * FROM media_rights_grants WHERE venue_id = ?', [$venue->id()]);
        $this->resetPurger();

        $failure = $this->failure(new RemoveVenuePhoto($number, $photo, Grantor::manager('019a0000-0000-7000-8000-0000000000aa')));

        self::assertInstanceOf(VenueRowNotFound::class, $failure);
        self::assertCount(1, $grants);
        self::assertSame($grants, $this->connection->fetchAllAssociative('SELECT * FROM media_rights_grants WHERE venue_id = ?', [$venue->id()]), 'the first revoker stays the revoker');
        self::assertSame([[], 0], [$this->purger()->venues, $this->purger()->listings]);
    }

    public function test_the_id_of_a_photo_of_another_venue_revokes_nothing(): void
    {
        $venue = $this->venue('Seehaus Eigen', 'seehaus-eigen');
        $foreign = VenuePhotoFactory::createOne();
        $foreignId = $foreign->id();
        $this->em->clear();

        $failure = $this->failure(new RemoveVenuePhoto($venue->number(), $foreignId, Grantor::manager('019a0000-0000-7000-8000-0000000000aa')));

        self::assertInstanceOf(VenueRowNotFound::class, $failure);
        self::assertSame([$foreignId, null], array_values($this->connection->fetchAssociative('SELECT venue_photo_id, revoked_at FROM media_rights_grants WHERE venue_photo_id = ?', [$foreignId]) ?: []), 'the foreign grant is as live as before');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM venue_photos WHERE id = ?', [$foreignId]));
    }

    public function test_the_revocation_and_the_removal_are_one_transaction(): void
    {
        $venue = $this->venue('Seehaus Atomar', 'seehaus-atomar');
        $number = $venue->number();
        $photo = $this->addPhoto($number, $this->upload(), grant: $this->staffGrant())->photoId;
        $files = $this->variantFiles($number, $photo);
        $this->resetPurger();
        // The database refuses to delete a photo, after the grant was ended in the same flush.
        $this->connection->executeStatement(<<<'SQL'
            CREATE FUNCTION test_no_photo_delete() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'no photo delete';
            END
            $$
            SQL);
        $this->connection->executeStatement('CREATE TRIGGER test_no_photo_delete BEFORE DELETE ON venue_photos FOR EACH ROW EXECUTE FUNCTION test_no_photo_delete()');

        $failure = $this->failure(new RemoveVenuePhoto($number, $photo, Grantor::staff(self::ADMIN)));

        self::assertStringContainsString('no photo delete', $failure->getMessage());
        $grant = $this->connection->fetchAssociative('SELECT venue_photo_id, revoked_at, revoked_by_type, revoked_by_ref FROM media_rights_grants WHERE venue_id = ?', [$venue->id()]);
        self::assertSame([$photo, null, null, null], array_values($grant ?: []), 'the grant is as live as the photo');
        self::assertSame($files, $this->variantFiles($number, $photo));
        self::assertSame([$photo], PublicPhotos::of($this->connection, $this->translator(), $number)['detail']);
        self::assertSame([[], 0], [$this->purger()->venues, $this->purger()->listings]);
    }

    public function test_revoking_moves_the_sitemap_lastmod(): void
    {
        $venue = $this->venue('Seehaus Lastmod', 'seehaus-lastmod');
        $number = $venue->number();
        $photo = $this->addPhoto($number, $this->upload(), grant: $this->staffGrant())->photoId;
        $this->addPhoto($number, $this->upload(), grant: $this->staffGrant());
        foreach (['venues' => 'id', 'venue_translations' => 'venue_id', 'venue_photos' => 'venue_id'] as $table => $column) {
            $this->connection->executeStatement(\sprintf("UPDATE %s SET updated_at = '2026-01-01 10:00:00+00' WHERE %s = ?", $table, $column), [$venue->id()]);
        }
        $sitemap = new VenueSitemapQuery($this->connection, ListingQueryTest::enabledLocale());
        self::assertSame('2026-01-01T10:00:00+00:00', $sitemap->page(1)[0]->lastmod->format('c'));

        $this->dispatch(new RemoveVenuePhoto($number, $photo, Grantor::staff(self::ADMIN)));

        self::assertGreaterThan(new \DateTimeImmutable('2026-06-01'), $sitemap->page(1)[0]->lastmod, 'the page lost an image');
        self::assertCount(1, $sitemap->page(1)[0]->imageUrls);
    }

    public function test_the_revoker_defaults_to_the_system_when_a_caller_names_nobody(): void
    {
        $venue = $this->venue('Seehaus System', 'seehaus-system');
        $number = $venue->number();
        $photo = $this->addPhoto($number, $this->upload(), grant: $this->staffGrant())->photoId;

        $this->dispatch(new RemoveVenuePhoto($number, $photo));

        self::assertSame(
            ['system', 'removal'],
            array_values($this->connection->fetchAssociative('SELECT revoked_by_type, revoked_by_ref FROM media_rights_grants WHERE venue_id = ?', [$venue->id()]) ?: []),
        );
    }

    private function resetPurger(): void
    {
        $this->purger()->venues = [];
        $this->purger()->listings = 0;
    }

    /**
     * @return array{listing: list<string>, merkliste: list<string>, similar: list<string>, detail: list<string>, rooms: list<string>, sitemap: list<string>}
     */
    private function seen(int $number, Venue $neighbour): array
    {
        return PublicPhotos::of($this->connection, $this->translator(), $number, $neighbour->id());
    }

    private function preview(int $number): VenueDetail
    {
        $locale = ListingQueryTest::enabledLocale();
        $detail = new VenueDetailQuery($this->connection, new TranslationJoin($locale), $locale, $this->translator())->detail($number, 'de', includeUnpublished: true);
        self::assertNotNull($detail);

        return $detail;
    }

    /**
     * An upload that carries the staff grant: its photo id and the path of its original.
     *
     * @return array{id: string, original: string}
     */
    private function uploadGranted(int $number): array
    {
        $source = $this->upload();
        $added = $this->addPhoto($number, $source, grant: $this->staffGrant());

        return ['id' => $added->photoId, 'original' => 'cms/'.$number.'/'.hash_file('sha256', $source).'.jpg'];
    }

    private function staffGrant(): PhotoGrant
    {
        return new PhotoGrant(Grantor::staff(self::ADMIN), GrantEvidence::StaffAttestation);
    }

    private function venue(string $name, string $slug): Venue
    {
        $venue = VenueFactory::createOne(['name' => $name, 'town' => DetailVenues::town('Zürich')]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'slug' => $slug]);

        return $venue;
    }

    /**
     * Every variant file of the photo under the public media directory (what nginx serves).
     *
     * @return list<string>
     */
    private function variantFiles(int $number, string $photoId): array
    {
        $files = glob($this->paths->photoDirectory($number).'/'.$photoId.'-*') ?: [];
        sort($files);

        return $files;
    }

    /**
     * @return array<string, array{venue_photo_id: ?string, grantor_type: string, grantor_ref: string, evidence: string, revoked_at: ?string, revoked_by_type: ?string, revoked_by_ref: ?string}> by original path
     */
    private function grants(string $venueId): array
    {
        /** @var list<array{original_path: string, venue_photo_id: ?string, grantor_type: string, grantor_ref: string, evidence: string, revoked_at: ?string, revoked_by_type: ?string, revoked_by_ref: ?string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT original_path, venue_photo_id, grantor_type, grantor_ref, evidence, revoked_at, revoked_by_type, revoked_by_ref FROM media_rights_grants WHERE venue_id = ?',
            [$venueId],
        );
        $byPath = [];
        foreach ($rows as $row) {
            $byPath[$row['original_path']] = $row;
        }

        return $byPath;
    }

    private function translator(): TranslatorInterface
    {
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        return $translator;
    }
}
