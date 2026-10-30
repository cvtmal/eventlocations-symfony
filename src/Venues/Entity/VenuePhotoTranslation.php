<?php

declare(strict_types=1);

namespace App\Venues\Entity;

use App\Shared\Uid\EntityId;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The alt text of a venue photo in one locale. A row exists only when there is a text;
 * #5/#8 compute a fallback alt otherwise.
 */
#[ORM\Entity]
#[ORM\Table(name: 'venue_photo_translations')]
#[ORM\Index(name: 'idx_venue_photo_translations_venue_photo_id', columns: ['venue_photo_id'])]
#[ORM\UniqueConstraint(name: 'uk_venue_photo_translations_venue_photo_id_locale', columns: ['venue_photo_id', 'locale'])]
class VenuePhotoTranslation
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: VenuePhoto::class)]
    #[ORM\JoinColumn(name: 'venue_photo_id', nullable: false, onDelete: 'CASCADE')]
    private VenuePhoto $photo;

    #[ORM\Column(type: Types::STRING, length: 2)]
    private string $locale;

    #[ORM\Column(type: Types::STRING, length: 700)]
    private string $alt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(VenuePhoto $photo, string $locale, string $alt)
    {
        $this->id = EntityId::generate();
        $this->photo = $photo;
        $this->locale = $locale;
        $this->alt = $alt;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function id(): string
    {
        \assert(null !== $this->id);

        return $this->id;
    }

    public function photo(): VenuePhoto
    {
        return $this->photo;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function alt(): string
    {
        return $this->alt;
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
