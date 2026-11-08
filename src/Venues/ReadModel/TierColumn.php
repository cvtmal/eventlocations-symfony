<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

/**
 * One tier as a column of the public matrix (G13): its name and price as the `tiers` row has them.
 */
final readonly class TierColumn
{
    public function __construct(
        public string $code,
        public string $name,
        public int $priceRappen,
        public bool $vatLine,
        public bool $promoted,
    ) {
    }

    public function isFree(): bool
    {
        return 0 === $this->priceRappen;
    }

    /**
     * The price in francs, for `swiss_number` with `priceDecimals()`.
     */
    public function francs(): int|float
    {
        return 0 === $this->priceRappen % 100 ? intdiv($this->priceRappen, 100) : $this->priceRappen / 100;
    }

    /**
     * Whole francs show no decimals («CHF 199»), anything else two («CHF 49.50»), as the `/konto` plan card does.
     */
    public function priceDecimals(): int
    {
        return 0 === $this->priceRappen % 100 ? 0 : 2;
    }
}
