<?php

declare(strict_types=1);

namespace App\Search\ReadModel;

use App\Venues\ValueObject\VenuePlace;

/**
 * A pin on the near-me map (#52): a published venue with its point, and its distance from the
 * visitor when there is one.
 */
final readonly class NearbyVenue
{
    public function __construct(
        public int $number,
        public string $name,
        public string $slug,
        public VenuePlace $place,
        public float $lat,
        public float $lng,
        public ?float $distanceKm,
    ) {
    }
}
