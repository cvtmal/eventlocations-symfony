<?php

declare(strict_types=1);

namespace App\Cms\Probe;

use App\Crm\Probe\CompanyCard;

/**
 * Exists only for ModuleBoundariesProbeTest: the forbidden edge Cms → Crm, which must fail.
 */
final readonly class ReachesIntoCrm
{
    public function __construct(public CompanyCard $card)
    {
    }
}
