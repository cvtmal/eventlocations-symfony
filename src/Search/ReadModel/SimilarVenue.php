<?php

declare(strict_types=1);

namespace App\Search\ReadModel;

use App\Venues\ValueObject\Tier;

/**
 * One card of "Ähnliche Locations" on the detail page (DP-15): photo, badge, name, town,
 * capacity.
 */
final readonly class SimilarVenue
{
    public function __construct(
        public string $id,
        public int $number,
        public string $slug,
        public string $name,
        public Tier $tier,
        public string $townName,
        public ?int $capacityMin,
        public int $capacityMax,
        public ?CardPhoto $photo,
    ) {
    }
}
