<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * One place a geocoder found: the name and address it knows, its position in degrees, and Google's place ID
 * ('' when the answer had none; #53's lookup reports it, the geocode report does not).
 */
final readonly class GeocodeHit
{
    public function __construct(
        public string $name,
        public string $address,
        public float $lat,
        public float $lng,
        public string $placeId = '',
    ) {
    }
}
