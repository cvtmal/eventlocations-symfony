<?php

declare(strict_types=1);

namespace App\Venues\Entity;

use App\Shared\Uid\EntityId;
use App\Venues\Exception\RoomOfAnotherVenue;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A photo of a venue, or of one of its rooms when `room` is set. Positions run per venue
 * (room NULL) and per room; uk_venue_photos_venue_id_room_id_position is NULLS NOT
 * DISTINCT, so the venue-level sequence is unique too. The alt text is in
 * VenuePhotoTranslation; #5 adds the image variants.
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

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    /**
     * @throws RoomOfAnotherVenue when the room belongs to another venue
     */
    public function __construct(Venue $venue, ?Room $room, int $position, string $originalPath)
    {
        if (null !== $room && $room->venue() !== $venue) {
            throw RoomOfAnotherVenue::forPhoto($room->id(), $venue->id());
        }

        $this->id = EntityId::generate();
        $this->venue = $venue;
        $this->room = $room;
        $this->position = $position;
        $this->originalPath = $originalPath;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
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

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
