<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Factory\MediaRightsGrantFactory;
use App\Factory\ModerationCaseFactory;
use App\Factory\ModerationCasePhotoFactory;
use App\Factory\RightsPolicyVersionFactory;
use App\Factory\RoomFactory;
use App\Factory\RoomTranslationFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Shared\Translation\TranslationJoin;
use App\Tests\Integration\Search\ListingQueryTest;
use App\Venues\Entity\RightsPolicyVersion;
use App\Venues\Entity\Venue;
use App\Venues\Entity\VenuePhoto;
use App\Venues\ReadModel\PhotoRightsQuery;
use App\Venues\ReadModel\PhotoRightsRow;
use App\Venues\ValueObject\GrantEvidence;
use App\Venues\ValueObject\Grantor;
use App\Venues\ValueObject\GrantorType;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The rights state of a venue's stored photos (G1), as the "Bildrechte" card reads it: every stored photo in the
 * order of the Fotos tab, with the facts of its live grant, and when and under which text a manager declared (the
 * declaration is personal: a colleague's does not count). Each test runs in a rolled-back transaction.
 */
final class PhotoRightsQueryTest extends KernelTestCase
{
    use Factories;

    private const string MANAGER = '019a0000-0000-7000-8000-0000000000aa';
    private const string COLLEAGUE = '019a0000-0000-7000-8000-0000000000bb';
    private const string ADMIN = '019a0000-0000-7000-8000-0000000000ad';

    private EntityManagerInterface $em;
    private Connection $connection;
    private PhotoRightsQuery $query;

    protected function setUp(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $this->query = new PhotoRightsQuery($this->connection, new TranslationJoin(ListingQueryTest::enabledLocale()));
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_an_unknown_or_out_of_range_venue_is_null(): void
    {
        self::assertNull($this->query->forVenue(999_999_999, 'de'));
        self::assertNull($this->query->forVenue(0, 'de'));
        self::assertNull($this->query->forVenue(2_147_483_648, 'de'));
        self::assertNull($this->query->forVenue(999_999_999, 'de', self::MANAGER));
        self::assertNull($this->query->declaration(999_999_999, self::MANAGER));
        self::assertNull($this->query->declaration(0, self::MANAGER));
        self::assertNull($this->query->declaration(2_147_483_648, self::MANAGER));
    }

    public function test_a_venue_without_photos_has_nothing_waiting_and_no_declaration(): void
    {
        $venue = $this->venue();

        $view = $this->query->forVenue($venue->number(), 'de', self::MANAGER);

        self::assertNotNull($view);
        self::assertSame([], $view->photos);
        self::assertSame(0, $view->ungrantedCount);
        self::assertNull($view->declaredAt);
        self::assertFalse($view->hasDeclaration());
    }

    public function test_gallery_first_then_rooms_in_room_order_each_by_position_with_the_room_name(): void
    {
        $venue = $this->venue();
        $second = RoomFactory::createOne(['venue' => $venue, 'position' => 2]);
        $first = RoomFactory::createOne(['venue' => $venue, 'position' => 1]);
        RoomTranslationFactory::createOne(['room' => $first, 'name' => 'Rittersaal']);
        RoomTranslationFactory::createOne(['room' => $second, 'name' => 'Gewölbekeller']);
        $inSecond = VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $second, 'position' => 1]);
        $inFirst2 = VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $first, 'position' => 2]);
        $inFirst1 = VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $first, 'position' => 1]);
        $gallery2 = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 2]);
        $gallery1 = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        VenuePhotoFactory::createOne(['position' => 1]);

        $view = $this->query->forVenue($venue->number(), 'de');

        self::assertNotNull($view);
        self::assertSame(
            [[$gallery1->id(), 1, null], [$gallery2->id(), 2, null], [$inFirst1->id(), 1, 'Rittersaal'], [$inFirst2->id(), 2, 'Rittersaal'], [$inSecond->id(), 1, 'Gewölbekeller']],
            array_map(static fn (PhotoRightsRow $photo): array => [$photo->photoId, $photo->position, $photo->roomName], $view->photos),
        );
    }

    public function test_a_granted_photo_carries_the_facts_of_its_live_grant(): void
    {
        $venue = $this->venue();
        $photo = VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 1]);
        MediaRightsGrantFactory::createOne([
            'photo' => $photo,
            'grantor' => Grantor::manager(self::MANAGER),
            'evidence' => GrantEvidence::Declaration,
            'derivatives' => true,
            'at' => new \DateTimeImmutable('2026-11-07 09:30:00 UTC'),
        ]);

        $view = $this->query->forVenue($venue->number(), 'de');

        self::assertNotNull($view);
        self::assertCount(1, $view->photos);
        $row = $view->photos[0];
        self::assertTrue($row->granted);
        self::assertSame(GrantEvidence::Declaration, $row->evidence);
        self::assertSame(GrantorType::Manager, $row->grantorType);
        self::assertSame('2026-11-07 09:30:00', $row->grantedAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        self::assertTrue($row->derivatives);
        self::assertSame('legacy', $row->policyVersion);
        self::assertSame(0, $view->ungrantedCount);
    }

    public function test_a_photo_an_open_complaint_names_is_held_until_the_complaint_is_decided(): void
    {
        $venue = $this->venue();
        $held = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        $decided = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 2]);
        VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 3]);
        ModerationCasePhotoFactory::createOne(['case' => ModerationCaseFactory::createOne(['venue' => $venue, 'venueReference' => null]), 'photo' => $held]);
        ModerationCasePhotoFactory::createOne(['case' => ModerationCaseFactory::new()->decided()->create(['venue' => $venue, 'venueReference' => null]), 'photo' => $decided]);

        $view = $this->query->forVenue($venue->number(), 'de');

        self::assertNotNull($view);
        self::assertSame([true, false, false], array_map(static fn (PhotoRightsRow $photo): bool => $photo->held, $view->photos));
        self::assertSame([true, true, true], array_map(static fn (PhotoRightsRow $photo): bool => $photo->granted, $view->photos), 'a held photo keeps its grant');
    }

    public function test_a_photo_without_a_live_grant_is_waiting_and_has_no_facts(): void
    {
        $venue = $this->venue();
        VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 1]);
        $revoked = VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 2]);
        MediaRightsGrantFactory::new()->revoked(Grantor::staff(self::ADMIN))->create(['photo' => $revoked]);
        VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 3]);

        $view = $this->query->forVenue($venue->number(), 'de');

        self::assertNotNull($view);
        self::assertSame([false, false, true], array_map(static fn (PhotoRightsRow $photo): bool => $photo->granted, $view->photos));
        self::assertSame(2, $view->ungrantedCount);
        foreach ([$view->photos[0], $view->photos[1]] as $row) {
            self::assertNull($row->evidence);
            self::assertNull($row->grantorType);
            self::assertNull($row->grantedAt);
            self::assertFalse($row->derivatives);
            self::assertNull($row->policyVersion);
        }
        self::assertSame(GrantEvidence::StaffAttestation, $view->photos[2]->evidence, 'the factory grant is the system staff attestation');
        self::assertSame(GrantorType::System, $view->photos[2]->grantorType);
    }

    public function test_the_declaration_is_the_latest_one_of_this_manager_for_this_venue_live_or_revoked(): void
    {
        $venue = $this->venue();
        $other = $this->venue();
        $earlier = VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 1]);
        $later = VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 2]);
        $elsewhere = VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $other, 'position' => 1]);
        $colleagues = VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 3]);
        $this->declare($earlier, self::MANAGER, '2026-11-01 10:00:00 UTC', revoked: true);
        $this->declare($later, self::MANAGER, '2026-11-05 11:00:00 UTC');
        $this->declare($elsewhere, self::MANAGER, '2026-11-09 12:00:00 UTC');
        $this->declare($colleagues, self::COLLEAGUE, '2026-11-08 09:00:00 UTC');

        self::assertSame('2026-11-05 11:00:00', $this->query->declaration($venue->number(), self::MANAGER)?->at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        $view = $this->query->forVenue($venue->number(), 'de', self::MANAGER);
        self::assertNotNull($view);
        self::assertTrue($view->hasDeclaration());
        self::assertSame('2026-11-05 11:00:00', $view->declaredAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));

        self::assertSame('2026-11-08 09:00:00', $this->query->declaration($venue->number(), self::COLLEAGUE)?->at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), 'each manager has their own');
    }

    public function test_a_colleagues_declaration_is_no_declaration_of_mine(): void
    {
        $venue = $this->venue();
        $this->declare(VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 1]), self::COLLEAGUE, '2026-11-05 11:00:00 UTC');

        self::assertNull($this->query->declaration($venue->number(), self::MANAGER));
        $mine = $this->query->forVenue($venue->number(), 'de', self::MANAGER);
        self::assertNotNull($mine);
        self::assertFalse($mine->hasDeclaration());
        self::assertNull($mine->declaredAt);

        $theirs = $this->query->forVenue($venue->number(), 'de', self::COLLEAGUE);
        self::assertNotNull($theirs);
        self::assertTrue($theirs->hasDeclaration());

        $staff = $this->query->forVenue($venue->number(), 'de');
        self::assertNotNull($staff);
        self::assertFalse($staff->hasDeclaration(), 'the staff card asks for no manager and has no declaration of its own');
    }

    public function test_the_declaration_names_the_policy_version_of_the_latest_one(): void
    {
        $venue = $this->venue();
        $first = RightsPolicyVersionFactory::createOne(['version' => 'aaaaaaaaaaaa']);
        $second = RightsPolicyVersionFactory::createOne(['version' => 'bbbbbbbbbbbb']);
        $this->declare(VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 1]), self::MANAGER, '2026-11-01 10:00:00 UTC', policy: $first);
        $this->declare(VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 2]), self::MANAGER, '2026-11-05 11:00:00 UTC', policy: $second);
        $this->declare(VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 3]), self::COLLEAGUE, '2026-11-09 12:00:00 UTC');

        self::assertSame('bbbbbbbbbbbb', $this->query->declaration($venue->number(), self::MANAGER)?->policyVersion, 'the words the manager saw last');
        self::assertSame('legacy', $this->query->declaration($venue->number(), self::COLLEAGUE)?->policyVersion);
    }

    public function test_a_declaration_survives_the_removal_of_its_photo(): void
    {
        $venue = $this->venue();
        $photo = VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 1]);
        MediaRightsGrantFactory::createOne(['photo' => $photo, 'grantor' => Grantor::manager(self::MANAGER), 'evidence' => GrantEvidence::Declaration]);
        $this->connection->executeStatement('UPDATE media_rights_grants SET revoked_at = now(), revoked_by_type = ?, revoked_by_ref = ? WHERE venue_photo_id = ?', ['manager', self::MANAGER, $photo->id()]);
        $this->connection->executeStatement('DELETE FROM venue_photos WHERE id = ?', [$photo->id()]);

        self::assertNotNull($this->query->declaration($venue->number(), self::MANAGER), 'the grant row stays as evidence, with no photo');
        $view = $this->query->forVenue($venue->number(), 'de', self::MANAGER);
        self::assertNotNull($view);
        self::assertSame([], $view->photos);
        self::assertTrue($view->hasDeclaration());
    }

    /**
     * @return iterable<string, array{GrantEvidence, GrantorType}>
     */
    public static function grantsThatAreNoManagerDeclaration(): iterable
    {
        yield 'a manager upload' => [GrantEvidence::Upload, GrantorType::Manager];
        yield 'a staff attestation' => [GrantEvidence::StaffAttestation, GrantorType::Staff];
        yield 'the system backfill' => [GrantEvidence::StaffAttestation, GrantorType::System];
        yield 'a declaration recorded by staff' => [GrantEvidence::Declaration, GrantorType::Staff];
    }

    #[DataProvider('grantsThatAreNoManagerDeclaration')]
    public function test_only_a_manager_declaration_is_a_declaration(GrantEvidence $evidence, GrantorType $by): void
    {
        $venue = $this->venue();
        $photo = VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 1]);
        MediaRightsGrantFactory::createOne(['photo' => $photo, 'grantor' => new Grantor($by, self::MANAGER), 'evidence' => $evidence]);

        self::assertNull($this->query->declaration($venue->number(), self::MANAGER));
        $view = $this->query->forVenue($venue->number(), 'de', self::MANAGER);
        self::assertNotNull($view);
        self::assertFalse($view->hasDeclaration());
        self::assertSame(0, $view->ungrantedCount, 'the photo is granted all the same');
    }

    private function venue(): Venue
    {
        return VenueFactory::new()->withoutLocation()->create(['status' => VenueStatus::Unpublished]);
    }

    /**
     * A manager's declaration on one photo, live or already revoked, under the `legacy` text unless told otherwise.
     */
    private function declare(VenuePhoto $photo, string $manager, string $at, bool $revoked = false, ?RightsPolicyVersion $policy = null): void
    {
        $grant = $revoked ? MediaRightsGrantFactory::new()->revoked() : MediaRightsGrantFactory::new();
        $grant->create([
            'photo' => $photo,
            'grantor' => Grantor::manager($manager),
            'evidence' => GrantEvidence::Declaration,
            'at' => new \DateTimeImmutable($at),
        ] + (null === $policy ? [] : ['policy' => $policy]));
    }
}
