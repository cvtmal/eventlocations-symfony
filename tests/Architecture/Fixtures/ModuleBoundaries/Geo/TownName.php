<?php

declare(strict_types=1);

namespace App\Geo\Probe;

/**
 * Exists only for ModuleBoundariesProbeTest: the target of the allowed Venues → Geo edge.
 */
final readonly class TownName
{
    public function __construct(public string $value)
    {
    }
}
