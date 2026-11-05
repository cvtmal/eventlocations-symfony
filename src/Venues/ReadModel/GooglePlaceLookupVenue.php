<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

/**
 * One venue the place ID lookup (#53) asks Google about: what the search text needs (name, canton code, the
 * German region; '' when absent) and our pin to measure Google's answer against. `lat`/`lng` are null without
 * coordinates and for the town centre ("Ortsmitte"), which says nothing about where the venue is.
 */
final readonly class GooglePlaceLookupVenue
{
    public function __construct(
        public int $number,
        public string $name,
        public string $cantonCode,
        public string $region,
        public ?float $lat,
        public ?float $lng,
        public bool $published,
    ) {
    }
}
