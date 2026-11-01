<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

/**
 * The pipeline (S13): one column per stage, and the figures of the head, which leave out won and lost
 * (`Pipeline.dc.html`).
 */
final readonly class DealBoard
{
    /**
     * @param list<DealColumn> $columns
     */
    public function __construct(
        public array $columns,
        public int $openCount,
        public int $openSumRappen,
    ) {
    }
}
