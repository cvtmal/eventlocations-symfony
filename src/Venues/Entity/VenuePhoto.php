<?php

declare(strict_types=1);

namespace App\Venues\Entity;

use App\Media\ValueObject\GeneratedImage;
use App\Media\ValueObject\ImageVariants;
use App\Shared\Uid\EntityId;
use App\Venues\Exception\RoomOfAnotherVenue;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A photo of a venue, or of one of its rooms when `room` is set. Positions run per venue
 * (room NULL) and per room; uk_venue_photos_venue_id_room_id_position is NULLS NOT
 * DISTINCT, so the venue-level sequence is unique too. The alt text is in
 * VenuePhotoTranslation.
 *
 * The variant columns (#5, S9) are NOT NULL, so a photo is written in two steps: first
 * Media\ImageVariantGenerator renders the variants of the original (`original_path`,
 * relative to MEDIA_ROOT) into a staging directory, then the photo is created with the
 * GeneratedImage and Media\MediaPublisher::copyVariants() publishes the files under its id.
 * app:media:regenerate re-renders in place and calls recordVariants().
 *
 * That the room belongs to this venue is checked here, not in the database.
 */
#[ORM\Entity]
#[ORM\Table(name: 'venue_photos')]
#[ORM\Index(name: 'idx_venue_photos_venue_id', columns: ['venue_id'])]
#[ORM\Index(name: 'idx_venue_photos_room_id', columns: ['room_id'])]
#[ORM\UniqueConstraint(name: 'uk_venue_photos_venue_id_room_id_position', columns: ['venue_id', 'room_id', 'position'])]
class VenuePhoto
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Venue::class)]
    #[ORM\JoinColumn(name: 'venue_id', nullable: false, onDelete: 'CASCADE')]
    private Venue $venue;

    #[ORM\ManyToOne(targetEntity: Room::class)]
    #[ORM\JoinColumn(name: 'room_id', nullable: true, onDelete: 'CASCADE')]
    private ?Room $room;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $position;

    #[ORM\Column(type: Types::STRING, length: 500)]
    private string $originalPath;

    /** The EXIF-oriented original's width in pixels. */
    #[ORM\Column(type: Types::INTEGER)]
    private int $width;

    #[ORM\Column(type: Types::INTEGER)]
    private int $height;

    /** `#rrggbb`, lower case: the placeholder colour. */
    #[ORM\Column(type: Types::STRING, length: 7)]
    private string $dominantColor;

    /** @var array{widths: list<int>, formats: list<string>} */
    #[ORM\Column(type: Types::JSONB)]
    private array $variants;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    /**
     * @throws RoomOfAnotherVenue when the room belongs to another venue
     */
    public function __construct(Venue $venue, ?Room $room, int $position, string $originalPath, GeneratedImage $image)
    {
        if (null !== $room && $room->venue() !== $venue) {
            throw RoomOfAnotherVenue::forPhoto($room->id(), $venue->id());
        }

        $this->id = EntityId::generate();
        $this->venue = $venue;
        $this->room = $room;
        $this->position = $position;
        $this->originalPath = $originalPath;
        $this->applyImage($image);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    /**
     * The variants were rendered again (app:media:regenerate).
     */
    public function recordVariants(GeneratedImage $image): void
    {
        $this->applyImage($image);
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function moveTo(int $position): void
    {
        $this->position = $position;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Moves the photo into the gallery (room null) or a room of its venue, at $position of
     * that scope (#25). The caller renumbers the scope it left.
     *
     * @throws RoomOfAnotherVenue when the room belongs to another venue
     */
    public function assignTo(?Room $room, int $position): void
    {
        if (null !== $room && $room->venue() !== $this->venue) {
            throw RoomOfAnotherVenue::forPhoto($room->id(), $this->venue->id());
        }

        $this->room = $room;
        $this->position = $position;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * A child row changed (an alt text, #25): moves updated_at, the photo's stale token.
     */
    public function touch(): void
    {
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

    public function room(): ?Room
    {
        return $this->room;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function originalPath(): string
    {
        return $this->originalPath;
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    public function dominantColor(): string
    {
        return $this->dominantColor;
    }

    public function variants(): ImageVariants
    {
        return ImageVariants::fromArray($this->variants);
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function applyImage(GeneratedImage $image): void
    {
        $this->width = $image->width;
        $this->height = $image->height;
        $this->dominantColor = $image->dominantColor;
        $this->variants = $image->variants->toArray();
    }
}
