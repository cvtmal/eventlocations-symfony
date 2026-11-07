<?php

declare(strict_types=1);

namespace App\Venues\Entity;

use App\Shared\Uid\EntityId;
use App\Venues\ValueObject\GrantEvidence;
use App\Venues\ValueObject\Grantor;
use App\Venues\ValueObject\GrantorType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The right to show one photo (G1, CTO_PRD F04 and §13.1): who grants it, on what evidence, under
 * which policy text, for which uses. A photo is public only with a live grant
 * (`Venues\PublicPhotoPolicy`).
 *
 * The row outlives its photo, not its venue: `venue_photo_id` becomes NULL when the photo row goes,
 * and `original_path` still names the file, but `venue_id` cascades, so deleting a venue
 * (`DeleteVenue`) deletes its grants with it. Nothing else deletes a grant: a revoked one is kept
 * (`revoked_at`, `revoked_by_*`), and a photo has at most one live grant
 * (`uk_media_rights_grants_live_photo`). There is no way to revive a revoked grant: a photo that
 * returns is a new upload with a new grant.
 */
#[ORM\Entity]
#[ORM\Table(name: 'media_rights_grants')]
#[ORM\Index(name: 'idx_media_rights_grants_venue_id', columns: ['venue_id'])]
#[ORM\Index(name: 'idx_media_rights_grants_venue_photo_id', columns: ['venue_photo_id'])]
#[ORM\Index(name: 'idx_media_rights_grants_policy_version', columns: ['policy_version'])]
#[ORM\UniqueConstraint(name: 'uk_media_rights_grants_live_photo', columns: ['venue_photo_id'], options: ['where' => '(revoked_at IS NULL)'])]
class MediaRightsGrant
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Venue::class)]
    #[ORM\JoinColumn(name: 'venue_id', nullable: false, onDelete: 'CASCADE')]
    private Venue $venue;

    #[ORM\ManyToOne(targetEntity: VenuePhoto::class)]
    #[ORM\JoinColumn(name: 'venue_photo_id', nullable: true, onDelete: 'SET NULL')]
    private ?VenuePhoto $photo = null;

    #[ORM\Column(type: Types::STRING, length: 500)]
    private string $originalPath;

    #[ORM\Column(type: Types::STRING, length: 10, enumType: GrantorType::class)]
    private GrantorType $grantorType;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $grantorRef;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: GrantEvidence::class)]
    private GrantEvidence $evidence;

    #[ORM\ManyToOne(targetEntity: RightsPolicyVersion::class)]
    #[ORM\JoinColumn(name: 'policy_version', referencedColumnName: 'version', nullable: false, onDelete: 'RESTRICT')]
    private RightsPolicyVersion $policy;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $scopeListing = true;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $scopeResizedCopies = true;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $scopeDerivatives;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(type: Types::STRING, length: 10, nullable: true, enumType: GrantorType::class)]
    private ?GrantorType $revokedByType = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $revokedByRef = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    /**
     * Listing and resized copies are what the site does with every photo, so every grant allows
     * them; the grantor's choice is the derivatives (promotional uses are parked, the choice is
     * recorded).
     */
    public function __construct(VenuePhoto $photo, Grantor $grantor, GrantEvidence $evidence, RightsPolicyVersion $policy, bool $derivatives, \DateTimeImmutable $at)
    {
        $this->id = EntityId::generate();
        $this->venue = $photo->venue();
        $this->photo = $photo;
        $this->originalPath = $photo->originalPath();
        $this->grantorType = $grantor->type;
        $this->grantorRef = $grantor->ref;
        $this->evidence = $evidence;
        $this->policy = $policy;
        $this->scopeDerivatives = $derivatives;
        $this->createdAt = $at;
        $this->updatedAt = $at;
    }

    /**
     * Ends the grant. The row stays; a photo without a live grant is not public.
     */
    public function revoke(Grantor $by, \DateTimeImmutable $at): void
    {
        if (null !== $this->revokedAt) {
            return;
        }
        $this->revokedAt = $at;
        $this->revokedByType = $by->type;
        $this->revokedByRef = $by->ref;
        $this->updatedAt = $at;
    }

    /**
     * The photo row is about to go. The database would set the column to NULL anyway; this keeps
     * the unit of work from pointing at an entity it is removing.
     */
    public function detach(): void
    {
        $this->photo = null;
    }

    public function id(): string
    {
        \assert(null !== $this->id);

        return $this->id;
    }

    public function venue(): Venue
    {
        return $this->venue;
    }

    /**
     * Null once the photo row is gone; `originalPath()` still names the file.
     */
    public function photo(): ?VenuePhoto
    {
        return $this->photo;
    }

    public function originalPath(): string
    {
        return $this->originalPath;
    }

    public function grantor(): Grantor
    {
        return new Grantor($this->grantorType, $this->grantorRef);
    }

    public function allowsListing(): bool
    {
        return $this->scopeListing;
    }

    public function allowsResizedCopies(): bool
    {
        return $this->scopeResizedCopies;
    }

    public function revokedBy(): ?Grantor
    {
        return null === $this->revokedByType || null === $this->revokedByRef ? null : new Grantor($this->revokedByType, $this->revokedByRef);
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isLive(): bool
    {
        return null === $this->revokedAt;
    }

    public function evidence(): GrantEvidence
    {
        return $this->evidence;
    }

    public function policyVersion(): string
    {
        return $this->policy->version();
    }

    public function allowsDerivatives(): bool
    {
        return $this->scopeDerivatives;
    }

    public function revokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }
}
