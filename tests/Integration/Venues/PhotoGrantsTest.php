<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Factory\MediaRightsGrantFactory;
use App\Factory\VenuePhotoFactory;
use App\Tests\Support\Cms\ResetsContentBlocks;
use App\Venues\Entity\MediaRightsGrant;
use App\Venues\PhotoGrants;
use App\Venues\RightsPolicy;
use App\Venues\ValueObject\GrantEvidence;
use App\Venues\ValueObject\Grantor;
use App\Venues\ValueObject\PhotoGrant;
use App\Venues\ValueObject\RightsPolicyText;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The one way a grant is written or ended (G1): `issue()` persists a live grant under the text in
 * force, or under the version the grant names, and flushes nothing, `releasePhoto()` ends every
 * live grant of a photo that is about to go and leaves the earlier ones as they were. Each test
 * runs in a rolled-back transaction.
 */
final class PhotoGrantsTest extends KernelTestCase
{
    use Factories;
    use ResetsContentBlocks;

    private EntityManagerInterface $em;
    private Connection $connection;

    protected function setUp(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $this->resetContentBlocks();
    }

    protected function tearDown(): void
    {
        $this->resetContentBlocks();
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_issue_persists_a_live_grant_for_the_photo_and_leaves_the_flush_to_the_caller(): void
    {
        $photo = VenuePhotoFactory::new()->withoutGrant()->create();
        $policy = $this->policy()->current();

        $grant = $this->grants()->issue($photo, new PhotoGrant(Grantor::manager('019a0000-0000-7000-8000-0000000000aa'), GrantEvidence::Declaration, derivatives: true));

        self::assertTrue($this->em->contains($grant));
        self::assertTrue($grant->isLive());
        self::assertSame($photo, $grant->photo());
        self::assertSame($policy->version, $grant->policyVersion());
        self::assertTrue($grant->allowsDerivatives());
        self::assertSame(0, $this->rows('media_rights_grants WHERE venue_photo_id = ?', [$photo->id()]), 'nothing is written before the caller flushes');
        self::assertSame(0, $this->rows('rights_policy_versions WHERE version = ?', [$policy->version]));

        $this->em->flush();

        self::assertSame(1, $this->rows('media_rights_grants WHERE venue_photo_id = ? AND revoked_at IS NULL', [$photo->id()]));
        self::assertSame(1, $this->rows('rights_policy_versions WHERE version = ?', [$policy->version]), 'the words of the version are written with its first grant');
    }

    public function test_issue_records_the_version_the_grant_names_not_the_text_in_force(): void
    {
        $agreed = RightsPolicyText::of('Der Wortlaut, dem die Verwalterin zugestimmt hat.');
        $this->policy()->record($agreed);
        $this->em->flush();
        $photo = VenuePhotoFactory::new()->withoutGrant()->create();
        $inForce = $this->policy()->current();
        self::assertNotSame($agreed->version, $inForce->version);

        $grant = $this->grants()->issue($photo, new PhotoGrant(Grantor::manager('019a0000-0000-7000-8000-0000000000aa'), GrantEvidence::Upload, policyVersion: $agreed->version));
        $this->em->flush();

        self::assertSame($agreed->version, $grant->policyVersion());
        self::assertSame($agreed->version, $this->connection->fetchOne('SELECT policy_version FROM media_rights_grants WHERE venue_photo_id = ?', [$photo->id()]));
        self::assertSame(0, $this->rows('rights_policy_versions WHERE version = ?', [$inForce->version]), 'nobody agreed to the text in force, so it is not written');
    }

    public function test_issue_falls_back_to_the_text_in_force_when_the_named_version_has_no_row(): void
    {
        $photo = VenuePhotoFactory::new()->withoutGrant()->create();
        $inForce = $this->policy()->current();

        $grant = $this->grants()->issue($photo, new PhotoGrant(Grantor::manager('019a0000-0000-7000-8000-0000000000aa'), GrantEvidence::Upload, policyVersion: 'zzzzzzzzzzzz'));
        $this->em->flush();

        self::assertSame($inForce->version, $grant->policyVersion());
        self::assertSame(1, $this->rows('rights_policy_versions WHERE version = ?', [$inForce->version]));
        self::assertSame(0, $this->rows('rights_policy_versions WHERE version = ?', ['zzzzzzzzzzzz']));
    }

    public function test_issue_knows_a_photo_that_is_not_flushed_yet(): void
    {
        $photo = VenuePhotoFactory::new()->withoutPersisting()->create();
        self::assertFalse($this->em->contains($photo));

        $this->grants()->issue($photo, new PhotoGrant(Grantor::system('import'), GrantEvidence::StaffAttestation));

        self::assertTrue($this->em->contains($photo), 'the grant points at a photo the unit of work knows');
    }

    public function test_two_grants_in_one_flush_share_one_policy_row(): void
    {
        $first = VenuePhotoFactory::new()->withoutGrant()->create();
        $second = VenuePhotoFactory::new()->withoutGrant()->create();
        $how = new PhotoGrant(Grantor::staff('019a0000-0000-7000-8000-0000000000ad'), GrantEvidence::StaffAttestation);
        $version = $this->policy()->current()->version;

        $this->grants()->issue($first, $how);
        $this->grants()->issue($second, $how);
        $this->em->flush();

        self::assertSame(2, $this->rows('media_rights_grants WHERE policy_version = ?', [$version]));
        self::assertSame(1, $this->rows('rights_policy_versions WHERE version = ?', [$version]));
    }

    public function test_release_ends_the_live_grant_and_cuts_the_link_before_the_photo_goes(): void
    {
        $photo = VenuePhotoFactory::createOne();
        $by = Grantor::staff('019a0000-0000-7000-8000-0000000000ad');

        $live = $this->grants()->releasePhoto($photo, $by);
        $this->em->remove($photo);
        $this->em->flush();

        self::assertSame(1, $live);
        $grant = $this->connection->fetchAssociative('SELECT venue_photo_id, original_path, revoked_at IS NOT NULL AS revoked, revoked_by_type, revoked_by_ref FROM media_rights_grants WHERE original_path = ?', [$photo->originalPath()]);
        self::assertSame([null, $photo->originalPath(), true, 'staff', '019a0000-0000-7000-8000-0000000000ad'], array_values($grant ?: []));
        self::assertSame(0, $this->rows('venue_photos WHERE original_path = ?', [$photo->originalPath()]));
    }

    public function test_release_leaves_an_earlier_revocation_as_it_was(): void
    {
        $photo = VenuePhotoFactory::createOne();
        MediaRightsGrantFactory::new()->revoked(Grantor::manager('019a0000-0000-7000-8000-0000000000aa'))->create(['photo' => $photo]);
        $this->em->flush();

        $live = $this->grants()->releasePhoto($photo, Grantor::staff('019a0000-0000-7000-8000-0000000000ad'));
        $this->em->flush();

        self::assertSame(1, $live, 'only one of the two was live');
        $revokers = $this->connection->fetchFirstColumn('SELECT revoked_by_type FROM media_rights_grants WHERE original_path = ? ORDER BY revoked_by_type', [$photo->originalPath()]);
        self::assertSame(['manager', 'staff'], $revokers, 'the earlier revoker is not overwritten');
        self::assertSame(2, $this->rows('media_rights_grants WHERE original_path = ? AND venue_photo_id IS NULL', [$photo->originalPath()]), 'both are cut from the photo');
    }

    public function test_releasing_a_photo_without_a_grant_answers_nothing_live(): void
    {
        $photo = VenuePhotoFactory::new()->withoutGrant()->create();

        $live = $this->grants()->releasePhoto($photo, Grantor::system('removal'));
        $this->em->remove($photo);
        $this->em->flush();

        self::assertSame(0, $live);
        self::assertSame(0, $this->rows('media_rights_grants WHERE original_path = ?', [$photo->originalPath()]));
    }

    public function test_the_entity_of_a_released_grant_no_longer_points_at_the_photo(): void
    {
        $photo = VenuePhotoFactory::createOne();
        $grant = $this->em->getRepository(MediaRightsGrant::class)->findOneBy(['photo' => $photo]);
        self::assertInstanceOf(MediaRightsGrant::class, $grant);

        $this->grants()->releasePhoto($photo, Grantor::system('removal'));

        self::assertNull($grant->photo());
        self::assertFalse($grant->isLive());
    }

    private function grants(): PhotoGrants
    {
        $grants = self::getContainer()->get(PhotoGrants::class);
        self::assertInstanceOf(PhotoGrants::class, $grants);

        return $grants;
    }

    private function policy(): RightsPolicy
    {
        $policy = self::getContainer()->get(RightsPolicy::class);
        self::assertInstanceOf(RightsPolicy::class, $policy);

        return $policy;
    }

    /**
     * @param list<mixed> $params
     */
    private function rows(string $from, array $params = []): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM '.$from, $params);
    }
}
