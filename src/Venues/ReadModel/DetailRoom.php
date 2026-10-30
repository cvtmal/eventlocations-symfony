<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

use App\Media\ValueObject\Picture;
use App\Venues\ValueObject\FloorMaterial;

/**
 * One card of the Räume carousel (DP-8) with the content of its "Rauminfos" (capacities per
 * seating, description) and "Eckdaten" (area, height, floor) dialogs.
 */
final readonly class DetailRoom
{
    /**
     * @param list<DetailSeating> $seatings in the order banquet, seminar, concert, apero
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public ?int $capacityMin,
        public ?int $capacityMax,
        public ?int $areaM2,
        public ?float $heightM,
        public ?FloorMaterial $floorMaterial,
        public array $seatings,
        public ?Picture $photo,
    ) {
    }

    /**
     * Whether the "Rauminfos" dialog has content.
     */
    public function hasInfo(): bool
    {
        return [] !== $this->seatings || null !== $this->description;
    }

    /**
     * Whether the "Eckdaten" dialog has content.
     */
    public function hasFacts(): bool
    {
        return null !== $this->areaM2 || null !== $this->heightM || null !== $this->floorMaterial;
    }
}
