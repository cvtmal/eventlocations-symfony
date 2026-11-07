<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues\Entity;

use App\Media\ValueObject\GeneratedImage;
use App\Media\ValueObject\ImageFormat;
use App\Media\ValueObject\ImageVariants;
use App\Venues\Entity\MediaRightsGrant;
use App\Venues\Entity\RightsPolicyVersion;
use App\Venues\Entity\VenuePhoto;
use App\Venues\ValueObject\GrantEvidence;
use App\Venues\ValueObject\Grantor;
use App\Venues\ValueObject\GrantorType;
use PHPUnit\Framework\TestCase;

/**
 * The grant of one photo (G1): what it records at the start, that ending it keeps the first
 * revocation, and that the row outlives the photo.
 */
final class MediaRightsGrantTest extends TestCase
{
    private const string GRANTED_AT = '2026-11-07 10:00:00+01';
    private const string REVOKED_AT = '2026-11-08 09:30:00+01';

    public function test_a_new_grant_records_who_what_under_which_text_and_for_which_uses(): void
    {
        $photo = $this->photo();
        $grantor = Grantor::manager('019a0000-0000-7000-8000-0000000000aa');
        $at = new \DateTimeImmutable(self::GRANTED_AT);

        $grant = new MediaRightsGrant($photo, $grantor, GrantEvidence::Declaration, $this->policy('3a1f0c9d4b7e'), true, $at);

        self::assertSame(36, \strlen($grant->id()));
        self::assertSame($photo, $grant->photo());
        self::assertSame($photo->venue(), $grant->venue());
        self::assertSame('demo/photo.jpg', $grant->originalPath());
        self::assertEquals($grantor, $grant->grantor());
        self::assertSame(GrantEvidence::Declaration, $grant->evidence());
        self::assertSame('3a1f0c9d4b7e', $grant->policyVersion());
        self::assertTrue($grant->allowsListing());
        self::assertTrue($grant->allowsResizedCopies());
        self::assertTrue($grant->allowsDerivatives());
        self::assertTrue($grant->isLive());
        self::assertNull($grant->revokedAt());
        self::assertNull($grant->revokedBy());
        self::assertEquals($at, $grant->createdAt());
        self::assertEquals($at, $grant->updatedAt());
    }

    public function test_the_derivatives_choice_is_recorded_as_given(): void
    {
        $grant = new MediaRightsGrant($this->photo(), Grantor::system('import'), GrantEvidence::StaffAttestation, $this->policy('legacy'), false, new \DateTimeImmutable(self::GRANTED_AT));

        self::assertFalse($grant->allowsDerivatives());
        self::assertTrue($grant->allowsListing(), 'listing and resized copies are what the site does with every photo');
        self::assertTrue($grant->allowsResizedCopies());
    }

    public function test_revoking_ends_the_grant_and_names_the_revoker(): void
    {
        $grant = $this->grant();
        $by = Grantor::staff('019a0000-0000-7000-8000-0000000000bb');
        $at = new \DateTimeImmutable(self::REVOKED_AT);

        $grant->revoke($by, $at);

        self::assertFalse($grant->isLive());
        self::assertEquals($at, $grant->revokedAt());
        self::assertEquals($by, $grant->revokedBy());
        self::assertEquals($at, $grant->updatedAt());
        self::assertEquals(new \DateTimeImmutable(self::GRANTED_AT), $grant->createdAt(), 'the evidence of the grant itself is not touched');
        self::assertEquals(Grantor::manager('019a0000-0000-7000-8000-0000000000aa'), $grant->grantor());
    }

    public function test_a_second_revocation_changes_nothing(): void
    {
        $grant = $this->grant();
        $first = Grantor::staff('019a0000-0000-7000-8000-0000000000bb');
        $grant->revoke($first, new \DateTimeImmutable(self::REVOKED_AT));

        $grant->revoke(Grantor::system('removal'), new \DateTimeImmutable('2026-12-01 00:00:00+01'));

        self::assertEquals($first, $grant->revokedBy());
        self::assertEquals(new \DateTimeImmutable(self::REVOKED_AT), $grant->revokedAt());
        self::assertEquals(new \DateTimeImmutable(self::REVOKED_AT), $grant->updatedAt());
        self::assertSame(GrantorType::Staff, $grant->revokedBy()?->type);
    }

    public function test_the_grant_keeps_the_original_path_when_the_photo_is_let_go(): void
    {
        $grant = $this->grant();

        $grant->detach();

        self::assertNull($grant->photo());
        self::assertSame('demo/photo.jpg', $grant->originalPath());
        self::assertTrue($grant->isLive(), 'detaching is the database\'s SET NULL, not a revocation');
    }

    private function grant(): MediaRightsGrant
    {
        return new MediaRightsGrant(
            $this->photo(),
            Grantor::manager('019a0000-0000-7000-8000-0000000000aa'),
            GrantEvidence::Declaration,
            $this->policy('3a1f0c9d4b7e'),
            false,
            new \DateTimeImmutable(self::GRANTED_AT),
        );
    }

    private function photo(): VenuePhoto
    {
        return new VenuePhoto(
            VenueObjects::venue(),
            null,
            1,
            'demo/photo.jpg',
            new GeneratedImage(1600, 1067, '#b9a58c', new ImageVariants([360, 640], [ImageFormat::Avif, ImageFormat::Webp, ImageFormat::Jpeg])),
        );
    }

    private function policy(string $version): RightsPolicyVersion
    {
        return new RightsPolicyVersion($version, 'Der Wortlaut.', new \DateTimeImmutable(self::GRANTED_AT));
    }
}
