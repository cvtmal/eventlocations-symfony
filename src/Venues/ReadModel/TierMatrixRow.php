<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

use App\Venues\ValueObject\TierFeature;

/**
 * One feature across every tier (G13): one cell per column, in column order. A flag is a bool; a limit is an int
 * (0 is "not included") or null for event types, which means every one that fits.
 */
final readonly class TierMatrixRow
{
    /**
     * @param list<bool|int|null> $cells
     */
    public function __construct(
        public TierFeature $feature,
        public array $cells,
    ) {
    }

    public function isLimit(): bool
    {
        return $this->feature->isLimit();
    }
}
