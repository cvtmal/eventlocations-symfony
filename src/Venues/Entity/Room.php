<?php

declare(strict_types=1);

namespace App\Venues\Entity;

use App\Shared\Uid\EntityId;
use App\Venues\ValueObject\FloorMaterial;
use App\Venues\ValueObject\Seating;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A room of a venue (DP-8 "Räume"), ordered by position. Name and description are in
 * RoomTranslation.
 *
 * The capacities per seating type are column pairs (capacity_<seating>_min/_max), one per
 * Seating case; read and write them through seatingMin()/seatingMax()/offerSeating().
 * A room's guest range follows its layouts (F4): once a layout states a maximum, capacity_max is
 * the greatest layout maximum and capacity_min the least minimum of those layouts (Seating::guestRange(),
 * chk_rooms_capacity_layouts); without one, the stated range stays. Offer the layouts before
 * reshape(), so a room whose layouts all went back to the stated range.
 * The height is a decimal string ('4.20') read back as a float. `content_key` is the room's
 * key in venue.yaml (#9); NULL for rooms not written by the import.
 */
#[ORM\Entity]
#[ORM\Table(name: 'rooms')]
#[ORM\Index(name: 'idx_rooms_venue_id', columns: ['venue_id'])]
#[ORM\UniqueConstraint(name: 'uk_rooms_venue_id_position', columns: ['venue_id', 'position'])]
#[ORM\UniqueConstraint(name: 'uk_rooms_venue_id_content_key', columns: ['venue_id', 'content_key'])]
class Room
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Venue::class)]
    #[ORM\JoinColumn(name: 'venue_id', nullable: false, onDelete: 'CASCADE')]
    private Venue $venue;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $position;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $capacityMin;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $capacityMax;

    #[ORM\Column(name: 'area_m2', type: Types::INTEGER, nullable: true)]
    private ?int $areaM2;

    #[ORM\Column(name: 'height_m', type: Types::DECIMAL, precision: 4, scale: 2, nullable: true)]
    private ?string $heightM;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true, enumType: FloorMaterial::class)]
    private ?FloorMaterial $floorMaterial;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $capacitySeminarMin = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $capacitySeminarMax = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $capacityBanquetMin = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $capacityBanquetMax = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $capacityAperoMin = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $capacityAperoMax = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $capacityConcertMin = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $capacityConcertMax = null;

    #[ORM\Column(name: 'capacity_u_shape_min', type: Types::INTEGER, nullable: true)]
    private ?int $capacityUShapeMin = null;

    #[ORM\Column(name: 'capacity_u_shape_max', type: Types::INTEGER, nullable: true)]
    private ?int $capacityUShapeMax = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $capacityBlockMin = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $capacityBlockMax = null;

    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $contentKey;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Venue $venue,
        int $position,
        ?int $capacityMin = null,
        ?int $capacityMax = null,
        ?int $areaM2 = null,
        ?string $heightM = null,
        ?FloorMaterial $floorMaterial = null,
        ?string $contentKey = null,
    ) {
        $this->id = EntityId::generate();
        $this->venue = $venue;
        $this->position = $position;
        $this->capacityMin = $capacityMin;
        $this->capacityMax = $capacityMax;
        $this->areaM2 = $areaM2;
        $this->heightM = $heightM;
        $this->floorMaterial = $floorMaterial;
        $this->contentKey = $contentKey;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function moveTo(int $position): void
    {
        $this->position = $position;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Replaces the room's facts; the height is a decimal string ('4.20'). The stated range gives
     * way to the layouts' range when a layout states a maximum.
     */
    public function reshape(?int $capacityMin, ?int $capacityMax, ?int $areaM2, ?string $heightM, ?FloorMaterial $floorMaterial): void
    {
        $this->capacityMin = $capacityMin;
        $this->capacityMax = $capacityMax;
        $this->areaM2 = $areaM2;
        $this->heightM = $heightM;
        $this->floorMaterial = $floorMaterial;
        $this->followLayouts();
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Sets the capacity range for one seating type; NULL/NULL removes it.
     */
    public function offerSeating(Seating $seating, ?int $min, ?int $max): void
    {
        match ($seating) {
            Seating::Seminar => [$this->capacitySeminarMin, $this->capacitySeminarMax] = [$min, $max],
            Seating::Banquet => [$this->capacityBanquetMin, $this->capacityBanquetMax] = [$min, $max],
            Seating::Apero => [$this->capacityAperoMin, $this->capacityAperoMax] = [$min, $max],
            Seating::Concert => [$this->capacityConcertMin, $this->capacityConcertMax] = [$min, $max],
            Seating::UShape => [$this->capacityUShapeMin, $this->capacityUShapeMax] = [$min, $max],
            Seating::Block => [$this->capacityBlockMin, $this->capacityBlockMax] = [$min, $max],
        };
        $this->followLayouts();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function seatingMin(Seating $seating): ?int
    {
        return match ($seating) {
            Seating::Seminar => $this->capacitySeminarMin,
            Seating::Banquet => $this->capacityBanquetMin,
            Seating::Apero => $this->capacityAperoMin,
            Seating::Concert => $this->capacityConcertMin,
            Seating::UShape => $this->capacityUShapeMin,
            Seating::Block => $this->capacityBlockMin,
        };
    }

    public function seatingMax(Seating $seating): ?int
    {
        return match ($seating) {
            Seating::Seminar => $this->capacitySeminarMax,
            Seating::Banquet => $this->capacityBanquetMax,
            Seating::Apero => $this->capacityAperoMax,
            Seating::Concert => $this->capacityConcertMax,
            Seating::UShape => $this->capacityUShapeMax,
            Seating::Block => $this->capacityBlockMax,
        };
    }

    private function followLayouts(): void
    {
        $range = Seating::guestRange(array_map(fn (Seating $seating): array => ['min' => $this->seatingMin($seating), 'max' => $this->seatingMax($seating)], Seating::cases()));
        if (null !== $range) {
            $this->capacityMin = $range['min'];
            $this->capacityMax = $range['max'];
        }
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

    public function capacityMin(): ?int
    {
        return $this->capacityMin;
    }

    public function capacityMax(): ?int
    {
        return $this->capacityMax;
    }

    public function areaM2(): ?int
    {
        return $this->areaM2;
    }

    public function heightM(): ?float
    {
        return null === $this->heightM ? null : (float) $this->heightM;
    }

    public function floorMaterial(): ?FloorMaterial
    {
        return $this->floorMaterial;
    }

    public function contentKey(): ?string
    {
        return $this->contentKey;
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
