<?php

declare(strict_types=1);

namespace App\Venues\Probe;

use App\Geo\Probe\TownName;

/**
 * Exists only for ModuleBoundariesProbeTest: the allowed edge Venues → Geo, which must stay clean.
 */
final readonly class VenueCard
{
    public function __construct(public TownName $town)
    {
    }
}
