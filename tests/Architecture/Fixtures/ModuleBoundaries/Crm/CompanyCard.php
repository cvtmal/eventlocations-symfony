<?php

declare(strict_types=1);

namespace App\Crm\Probe;

/**
 * Exists only for ModuleBoundariesProbeTest: the target of the forbidden Cms → Crm edge.
 */
final readonly class CompanyCard
{
    public function __construct(public string $name)
    {
    }
}
