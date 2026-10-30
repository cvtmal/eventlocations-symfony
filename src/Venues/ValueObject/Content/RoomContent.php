<?php

declare(strict_types=1);

namespace App\Venues\ValueObject\Content;

use App\Venues\ValueObject\FloorMaterial;

/**
 * One room of venue.yaml; the list order is its position.
 */
final readonly class RoomContent
{
    /**
     * @param array<string, array{name: string, description: ?string}> $translations by locale
     * @param ?string                                                  $heightM      '%.2f'
     * @param array<string, array{min: ?int, max: ?int}>               $seating      by Seating value
     * @param list<PhotoContent>                                       $photos
     */
    public function __construct(
        public string $key,
        public array $translations,
        public ?int $capacityMin,
        public ?int $capacityMax,
        public ?int $areaM2,
        public ?string $heightM,
        public ?FloorMaterial $floorMaterial,
        public array $seating,
        public array $photos,
    ) {
    }
}
