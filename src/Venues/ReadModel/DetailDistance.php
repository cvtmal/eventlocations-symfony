<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

use App\Venues\ValueObject\Access;

/**
 * One line of the Standort section (DP-10): "{km} km bis Bahnhof {name}". The name is optional.
 */
final readonly class DetailDistance
{
    public function __construct(
        public Access $access,
        public float $km,
        public ?string $name,
    ) {
    }
}
