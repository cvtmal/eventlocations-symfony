<?php

declare(strict_types=1);

namespace App\Geo\ReadModel;

/**
 * A `place` filter value resolved to its town: the official name, the ZIP to show and the coordinates.
 */
final readonly class ResolvedPlace
{
    public function __construct(
        public string $name,
        public string $zip,
        public float $lat,
        public float $lng,
    ) {
    }

    public function label(): string
    {
        return "{$this->zip} {$this->name}";
    }
}
