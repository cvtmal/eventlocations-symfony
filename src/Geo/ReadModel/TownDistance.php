<?php

declare(strict_types=1);

namespace App\Geo\ReadModel;

/**
 * A town within a radius, with its great-circle distance from the centre town.
 */
final readonly class TownDistance
{
    public function __construct(
        public string $townId,
        public string $name,
        public string $cantonCode,
        public string $zip,
        public float $distanceKm,
    ) {
    }
}
