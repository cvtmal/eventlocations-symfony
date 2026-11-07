<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues\Cms;

use App\Factory\RoomFactory;
use App\Factory\RoomTranslationFactory;
use App\Factory\VenueFactory;
use App\Factory\VenueTranslationFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Cms\ResetsContentBlocks;
use App\Tests\Support\Venues\DetailVenues;
use App\Tests\Support\Venues\PublicPhotos;
use App\Venues\Command\AddVenuePhoto;
use App\Venues\Entity\Venue;
use App\Venues\RightsPolicy;
use App\Venues\ValueObject\GrantEvidence;
use App\Venues\ValueObject\Grantor;
use App\Venues\ValueObject\PhotoGrant;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * AddVenuePhoto with a PhotoGrant (G1): the caller that can vouch for the file (staff, or a manager
 * whose declaration is on record) makes the photo public in the same transaction; without a grant
 * the photo is stored and private. The photo and its grant stand or fall together.
 */
final class AddVenuePhotoGrantTest extends KernelTestCase
{
    use Factories;
    use HandlesVenueMedia {
        setUp as private setUpMedia;
        tearDown as private tearDownMedia;
    }
    use ImportsTownDataset;
    use ResetsContentBlocks;

    private const string MANAGER = '019a0000-0000-7000-8000-0000000000aa';
    private const string ADMIN = '019a0000-0000-7000-8000-0000000000ad';

    protected function setUp(): void
    {
        $this->setUpMedia();
        $this->resetContentBlocks();
    }

    protected function tearDown(): void
    {
        $this->resetContentBlocks();
        $this->tearDownMedia();
    }

    public function test_an_upload_with_a_grant_is_public_at_once_and_the_grant_is_recorded(): void
    {
        $venue = $this->venue();
        $number = $venue->number();
        $source = $this->upload();
        $policy = self::getContainer()->get(RightsPolicy::class);
        self::assertInstanceOf(RightsPolicy::class, $policy);
        $text = $policy->current();

        $added = $this->addPhoto($number, $source, grant: new PhotoGrant(Grantor::manager(self::MANAGER), GrantEvidence::Upload, derivatives: true));

        $grant = $this->connection->fetchAssociative(
            'SELECT venue_id, venue_photo_id, original_path, grantor_type, grantor_ref, evidence, policy_version, scope_listing, scope_resized_copies, scope_derivatives, revoked_at FROM media_rights_grants',
        );
        self::assertSame(
            [$venue->id(), $added->photoId, 'cms/'.$number.'/'.hash_file('sha256', $source).'.jpg', 'manager', self::MANAGER, 'upload', $text->version, true, true, true, null],
            array_values($grant ?: []),
        );
        self::assertSame($text->markdown, $this->connection->fetchOne('SELECT body_markdown FROM rights_policy_versions WHERE version = ?', [$text->version]));
        $seen = PublicPhotos::of($this->connection, $this->translator(), $number);
        self::assertSame([$added->photoId], $seen['detail']);
        self::assertSame([$added->photoId], $seen['listing']);
        self::assertSame([$added->photoId], $seen['sitemap']);
    }

    public function test_an_upload_without_a_grant_leaves_no_grant_and_the_audit_row_names_no_evidence(): void
    {
        $venue = $this->venue();
        $number = $venue->number();

        $this->addPhoto($number, $this->upload());
        $this->addPhoto($number, $this->upload(), grant: new PhotoGrant(Grantor::staff(self::ADMIN), GrantEvidence::StaffAttestation));

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM media_rights_grants'));
        $rows = $this->auditRows($number);
        // jsonb does not keep the order of the keys.
        self::assertEquals([['room_id' => null, 'type' => 'jpg'], ['room_id' => null, 'type' => 'jpg', 'evidence' => 'staff_attestation']], array_map(static fn (array $row): array => $row['changes'], $rows));
    }

    public function test_a_room_photo_gets_its_grant_too(): void
    {
        $venue = $this->venue();
        $room = RoomFactory::createOne(['venue' => $venue, 'position' => 1]);
        RoomTranslationFactory::createOne(['room' => $room, 'name' => 'Saal']);

        $added = $this->addPhoto($venue->number(), $this->upload(), $room->id(), grant: new PhotoGrant(Grantor::staff(self::ADMIN), GrantEvidence::StaffAttestation));

        self::assertSame($added->photoId, $this->connection->fetchOne('SELECT venue_photo_id FROM media_rights_grants WHERE venue_id = ?', [$venue->id()]));
        $seen = PublicPhotos::of($this->connection, $this->translator(), $venue->number());
        self::assertSame([$added->photoId], $seen['rooms']);
        self::assertSame([], $seen['detail'], 'a room photo is not a gallery photo');
        self::assertSame([], $seen['listing']);
    }

    public function test_the_same_file_again_keeps_the_grant_state_it_has(): void
    {
        $venue = $this->venue();
        $number = $venue->number();
        $private = $this->upload();
        $public = $this->upload();
        $staff = new PhotoGrant(Grantor::staff(self::ADMIN), GrantEvidence::StaffAttestation);
        $manager = new PhotoGrant(Grantor::manager(self::MANAGER), GrantEvidence::Upload);

        $privateId = $this->addPhoto($number, $private)->photoId;
        $publicId = $this->addPhoto($number, $public, grant: $staff)->photoId;
        $again = $this->addPhoto($number, $private, grant: $staff);
        $publicAgain = $this->addPhoto($number, $public, grant: $manager);

        self::assertSame([true, $privateId], [$again->duplicate, $again->photoId]);
        self::assertSame([true, $publicId], [$publicAgain->duplicate, $publicAgain->photoId]);
        self::assertSame([[$publicId, 'staff']], array_map(
            static fn (array $row): array => [$row['venue_photo_id'], $row['grantor_type']],
            $this->connection->fetchAllAssociative('SELECT venue_photo_id, grantor_type FROM media_rights_grants WHERE venue_id = ?', [$venue->id()]),
        ), 'a duplicate neither grants the private photo nor adds a second grant to the public one');
    }

    public function test_a_failed_publish_leaves_neither_the_photo_nor_its_grant(): void
    {
        $venue = $this->venue();
        $number = $venue->number();
        $this->em->clear();
        $staging = self::getContainer()->getParameter('app.import_staging_dir');
        self::assertIsString($staging);
        $listener = new class($staging) {
            public int $removed = 0;

            public function __construct(
                private readonly string $staging,
            ) {
            }

            public function postFlush(): void
            {
                foreach (glob($this->staging.'/*/v-*.jpg') ?: [] as $variant) {
                    $this->removed += (int) unlink($variant);
                }
            }
        };
        $this->em->getEventManager()->addEventListener(Events::postFlush, $listener);

        $failure = $this->failure(new AddVenuePhoto($number, null, $this->upload(), 'jpg', new PhotoGrant(Grantor::staff(self::ADMIN), GrantEvidence::StaffAttestation)));

        $this->em->getEventManager()->removeEventListener(Events::postFlush, $listener);
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertGreaterThan(0, $listener->removed);
        self::assertSame([], $this->scope($venue->id()));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM media_rights_grants WHERE venue_id = ?', [$venue->id()]), 'the grant was flushed with the photo and goes with it');
        self::assertSame([[], 0], [$this->purger()->venues, $this->purger()->listings]);
    }

    private function venue(): Venue
    {
        $venue = VenueFactory::createOne(['name' => 'Seehaus Upload', 'town' => DetailVenues::town('Zürich')]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'slug' => 'seehaus-upload']);

        return $venue;
    }

    private function translator(): TranslatorInterface
    {
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        return $translator;
    }
}
