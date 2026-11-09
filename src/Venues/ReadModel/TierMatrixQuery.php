<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

/**
 * The public tier matrix (G13) from the one ordered read of every tier; the venue counts it also carries never reach
 * the page (D18: no counts).
 */
final readonly class TierMatrixQuery
{
    public function __construct(
        private TierDefinitionsQuery $definitions,
    ) {
    }

    public function matrix(): TierMatrix
    {
        return TierMatrix::fromDefinitions($this->definitions->all());
    }
}
