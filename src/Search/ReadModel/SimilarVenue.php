<?php

declare(strict_types=1);

namespace App\Search\ReadModel;

use App\Venues\ValueObject\VenuePlace;

/**
 * One card of "Ähnliche Locations" on the detail page (DP-15): photo, "Top-Eintrag" label, name,
 * town, capacity, and the «Kurzfristig frei» badge (G14, `$lastMinute`: a live offer the plan shows).
 */
final readonly class SimilarVenue
{
    public function __construct(
        public string $id,
        public int $number,
        public string $slug,
        public string $name,
        public bool $promoted,
        public VenuePlace $place,
        public ?int $capacityMin,
        public int $capacityMax,
        public ?CardPhoto $photo,
        public bool $lastMinute = false,
    ) {
    }
}
