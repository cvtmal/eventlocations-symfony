<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * One place a geocoder found: the name and address it knows, and its position in degrees.
 */
final readonly class GeocodeHit
{
    public function __construct(
        public string $name,
        public string $address,
        public float $lat,
        public float $lng,
    ) {
    }
}
