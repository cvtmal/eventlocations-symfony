<?php

declare(strict_types=1);

namespace App\Geo\Probe;

use App\Venues\Probe\VenueCard;

/**
 * Exists only for ModuleBoundariesProbeTest: the forbidden edge Geo → Venues, which must fail.
 */
final readonly class ReachesIntoVenues
{
    public function __construct(public VenueCard $card)
    {
    }
}
