<?php

declare(strict_types=1);

namespace App\Search\Probe;

/**
 * Exists only for ModuleBoundariesProbeTest: the target of the forbidden Portal → Search edge.
 */
final readonly class ResultCard
{
    public function __construct(public string $name)
    {
    }
}
