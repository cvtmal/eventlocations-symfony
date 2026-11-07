<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Factory\MediaRightsGrantFactory;
use App\Factory\RoomFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Venues\PublicPhotoPolicy;
use App\Venues\ValueObject\Grantor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The contract the other tests build on (G1): a photo made by VenuePhotoFactory is public, because it
 * has a live grant, unless `withoutGrant()` says it is a fresh upload; and `revoked()` makes a grant
 * that is over. Each test runs in a rolled-back transaction.
 */
final class PhotoFactoryGrantsTest extends KernelTestCase
{
    use Factories;

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

    public function test_a_factory_photo_has_a_live_grant_of_the_system_and_is_public(): void
    {
        $photo = VenuePhotoFactory::createOne();

        $grant = $this->connection->fetchAssociative('SELECT venue_id, venue_photo_id, original_path, grantor_type, grantor_ref, evidence, policy_version, scope_derivatives, revoked_at FROM media_rights_grants WHERE venue_photo_id = ?', [$photo->id()]);

        self::assertSame(
            [$photo->venue()->id(), $photo->id(), $photo->originalPath(), 'system', 'demo', 'staff_attestation', 'legacy', false, null],
            array_values($grant ?: []),
        );
        self::assertSame([$photo->id()], (new PublicPhotoPolicy($this->connection))->filter([$photo->id()]));
    }

    public function test_without_a_grant_the_photo_is_stored_and_private(): void
    {
        $photo = VenuePhotoFactory::new()->withoutGrant()->create();

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM venue_photos WHERE id = ?', [$photo->id()]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM media_rights_grants WHERE original_path = ?', [$photo->originalPath()]));
        self::assertSame([], (new PublicPhotoPolicy($this->connection))->filter([$photo->id()]));
    }

    public function test_every_photo_of_a_batch_and_every_room_photo_gets_its_own_grant(): void
    {
        $venue = VenueFactory::createOne();
        $room = RoomFactory::createOne(['venue' => $venue]);

        $gallery = VenuePhotoFactory::new(['venue' => $venue])->sequence([['position' => 1], ['position' => 2], ['position' => 3]])->create();
        $inRoom = VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $room]);

        self::assertCount(3, $gallery);
        self::assertSame(4, (int) $this->connection->fetchOne('SELECT count(*) FROM media_rights_grants WHERE venue_id = ?', [$venue->id()]));
        self::assertSame(4, (int) $this->connection->fetchOne('SELECT count(DISTINCT venue_photo_id) FROM media_rights_grants WHERE venue_id = ?', [$venue->id()]));
        self::assertNotNull($inRoom->room());
    }

    public function test_a_revoked_grant_is_stored_already_over_beside_the_live_one(): void
    {
        $photo = VenuePhotoFactory::createOne();

        MediaRightsGrantFactory::new()->revoked(Grantor::staff('019a0000-0000-7000-8000-0000000000ad'))->create(['photo' => $photo]);

        $grants = $this->connection->fetchAllAssociative('SELECT revoked_at IS NOT NULL AS revoked, revoked_by_type, revoked_by_ref FROM media_rights_grants WHERE venue_photo_id = ? ORDER BY revoked', [$photo->id()]);
        self::assertSame([[false, null, null], [true, 'staff', '019a0000-0000-7000-8000-0000000000ad']], array_map(static fn (array $grant): array => array_values($grant), $grants));
        self::assertSame([$photo->id()], (new PublicPhotoPolicy($this->connection))->filter([$photo->id()]), 'the live grant still makes it public');
    }

    public function test_a_photo_whose_only_grant_is_revoked_is_private(): void
    {
        $photo = VenuePhotoFactory::new()->withoutGrant()->create();

        MediaRightsGrantFactory::new()->revoked()->create(['photo' => $photo]);

        self::assertSame(['system', 'test'], array_values($this->connection->fetchAssociative('SELECT revoked_by_type, revoked_by_ref FROM media_rights_grants WHERE venue_photo_id = ?', [$photo->id()]) ?: []));
        self::assertSame([], (new PublicPhotoPolicy($this->connection))->filter([$photo->id()]));
    }
}
