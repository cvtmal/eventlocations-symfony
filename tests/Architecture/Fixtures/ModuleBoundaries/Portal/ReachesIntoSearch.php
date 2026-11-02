<?php

declare(strict_types=1);

namespace App\Portal\Probe;

use App\Search\Probe\ResultCard;

/**
 * Exists only for ModuleBoundariesProbeTest: the forbidden edge Portal → Search, which must fail.
 */
final readonly class ReachesIntoSearch
{
    public function __construct(public ResultCard $card)
    {
    }
}
