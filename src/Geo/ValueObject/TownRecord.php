<?php

declare(strict_types=1);

namespace App\Geo\ValueObject;

/**
 * One town of the towns dataset.
 *
 * A parsed CSV row carries its own ZIP in $zips; a collapsed record carries every ZIP of
 * its (canton, name) group, sorted, while $zip is the lowest one.
 */
final readonly class TownRecord
{
    /**
     * @param list<string> $zips
     */
    public function __construct(
        public string $zip,
        public string $name,
        public Canton $canton,
        public int $bfsNumber,
        public float $lat,
        public float $lng,
        public int $population,
        public array $zips,
    ) {
    }

    public function key(): string
    {
        return $this->canton->value.'|'.$this->name;
    }
}
