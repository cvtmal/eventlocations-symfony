<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

/**
 * A column of the board. `count` and `sumRappen` hold every deal of the filter, `cards` only as many as the board shows
 * (D16), so a head always tells the truth.
 */
final readonly class DealColumn
{
    /**
     * @param list<DealBoardCard> $cards
     */
    public function __construct(
        public DealStageView $stage,
        public int $count,
        public int $sumRappen,
        public array $cards,
    ) {
    }
}
