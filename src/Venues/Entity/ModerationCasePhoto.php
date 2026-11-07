<?php

declare(strict_types=1);

namespace App\Venues\Entity;

use App\Shared\Uid\EntityId;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A photo a rights complaint names (G2). While its case is open the photo is hidden
 * (`Venues\PublicPhotoPolicy`); the row outlives the photo (`venue_photo_id` SET NULL) and `original_path` still
 * names the file, like a rights grant (G1).
 */
#[ORM\Entity]
#[ORM\Table(name: 'moderation_case_photos')]
#[ORM\Index(name: 'idx_moderation_case_photos_case_id', columns: ['case_id'])]
#[ORM\Index(name: 'idx_moderation_case_photos_venue_photo_id', columns: ['venue_photo_id'])]
#[ORM\UniqueConstraint(name: 'uk_moderation_case_photos_case_photo', columns: ['case_id', 'venue_photo_id'])]
class ModerationCasePhoto
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: ModerationCase::class)]
    #[ORM\JoinColumn(name: 'case_id', nullable: false, onDelete: 'CASCADE')]
    private ModerationCase $case;

    #[ORM\ManyToOne(targetEntity: VenuePhoto::class)]
    #[ORM\JoinColumn(name: 'venue_photo_id', nullable: true, onDelete: 'SET NULL')]
    private ?VenuePhoto $photo;

    #[ORM\Column(type: Types::STRING, length: 500)]
    private string $originalPath;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(ModerationCase $case, VenuePhoto $photo, \DateTimeImmutable $at)
    {
        $this->id = EntityId::generate();
        $this->case = $case;
        $this->photo = $photo;
        $this->originalPath = $photo->originalPath();
        $this->createdAt = $at;
        $this->updatedAt = $at;
    }

    /**
     * The photo row is about to go (staff decided `remove`). The database would set the column to NULL anyway;
     * this keeps the unit of work from pointing at an entity it is removing.
     */
    public function detach(\DateTimeImmutable $at): void
    {
        $this->photo = null;
        $this->updatedAt = $at;
    }

    public function id(): string
    {
        \assert(null !== $this->id);

        return $this->id;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function case(): ModerationCase
    {
        return $this->case;
    }

    public function photo(): ?VenuePhoto
    {
        return $this->photo;
    }

    public function originalPath(): string
    {
        return $this->originalPath;
    }
}
