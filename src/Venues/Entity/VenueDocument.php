<?php

declare(strict_types=1);

namespace App\Venues\Entity;

use App\Shared\Uid\EntityId;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A downloadable PDF of a venue (DP-12), ordered by position. `path` is relative to
 * MEDIA_ROOT (S9); the title is in VenueDocumentTranslation.
 */
#[ORM\Entity]
#[ORM\Table(name: 'venue_documents')]
#[ORM\Index(name: 'idx_venue_documents_venue_id', columns: ['venue_id'])]
#[ORM\UniqueConstraint(name: 'uk_venue_documents_venue_id_position', columns: ['venue_id', 'position'])]
class VenueDocument
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Venue::class)]
    #[ORM\JoinColumn(name: 'venue_id', nullable: false, onDelete: 'CASCADE')]
    private Venue $venue;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $position;

    #[ORM\Column(type: Types::STRING, length: 500)]
    private string $path;

    #[ORM\Column(type: Types::INTEGER)]
    private int $sizeBytes;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Venue $venue, int $position, string $path, int $sizeBytes)
    {
        $this->id = EntityId::generate();
        $this->venue = $venue;
        $this->position = $position;
        $this->path = $path;
        $this->sizeBytes = $sizeBytes;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function moveTo(int $position): void
    {
        $this->position = $position;
        $this->updatedAt = new \DateTimeImmutable();
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

    public function position(): int
    {
        return $this->position;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function sizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
